#!/usr/bin/env python3
"""Prepare a private MySQL 5.7 deployment bundle; never connect to a database."""

import argparse
import hashlib
import json
import re
from pathlib import Path


TOKEN = re.compile(r"'(?:''|\\.|[^'\\])*'|`[^`]*`|--[^\n]*|/\*[\s\S]*?\*/|[A-Za-z_][A-Za-z_0-9]*|.", re.S)


def split_sql(text, separator=';', nested=False):
    """Split SQL outside comments, quoted strings and (optionally) parentheses."""
    parts, current, depth = [], [], 0
    for match in TOKEN.finditer(text):
        token = match.group()
        if token.startswith(('--', '/*')):
            current.append(' ')
            continue
        if token == '(':
            depth += 1
        elif token == ')':
            depth -= 1
        if token == separator and (not nested or depth == 0):
            if ''.join(current).strip():
                parts.append(''.join(current).strip())
            current = []
        else:
            current.append(token)
    if ''.join(current).strip():
        parts.append(''.join(current).strip())
    return parts


def new_expression(expression):
    keywords = {'IN', 'IS', 'NULL', 'NOT', 'OR', 'AND', 'TRUE', 'FALSE'}
    return ''.join(
        'NEW.' + token if re.fullmatch(r'[A-Za-z_][A-Za-z_0-9]*', token) and token.upper() not in keywords else token
        for token in (match.group() for match in TOKEN.finditer(expression))
    )


def trigger_statements(table, checks):
    for name, expression in checks:
        for event, suffix in [('INSERT', 'bi'), ('UPDATE', 'bu')]:
            trigger = name + '_' + suffix
            if len(trigger) > 64:
                raise ValueError('Trigger name exceeds MySQL limit: ' + trigger)
            yield (
                f'CREATE TRIGGER `{trigger}` BEFORE {event} ON `{table}` FOR EACH ROW\n'
                f'BEGIN\n  IF ({new_expression(expression)}) IS FALSE THEN\n'
                f"    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHECK {name} violated';\n"
                '  END IF;\nEND'
            )


def adapt_statement(statement):
    statement = statement.replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci')
    create = re.match(r'CREATE TABLE (\w+)\s*\(', statement, re.I)
    alter = re.match(r'ALTER TABLE (\w+)\s+', statement, re.I)
    if not create and not alter:
        return [statement], []
    table = (create or alter).group(1)
    if create:
        start, end = statement.index('('), statement.rfind(')')
        clauses = split_sql(statement[start + 1:end], ',', True)
    else:
        clauses = split_sql(statement[alter.end():], ',', True)
    kept, checks = [], []
    for clause in clauses:
        check = re.fullmatch(r'(?:ADD\s+)?CONSTRAINT\s+(\w+)\s+CHECK\s*\((.*)\)', clause, re.I | re.S)
        if check:
            checks.append(check.groups())
        else:
            if re.search(r'\bCHECK\s*\(', clause, re.I):
                raise ValueError('Unsupported CHECK clause: ' + clause)
            kept.append(clause)
    if create:
        adapted = statement[:start + 1] + '\n    ' + ',\n    '.join(kept) + '\n' + statement[end:]
        if not re.search(r'\bENGINE\s*=', adapted, re.I):
            adapted += ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    else:
        adapted = statement[:alter.end()] + ',\n    '.join(kept)
    result = [adapted] if kept else []
    result.extend(trigger_statements(table, checks))
    return result, [{'table': table, 'name': name, 'expression': expression} for name, expression in checks]


def prepare(source, output):
    if source.resolve() == output.resolve():
        raise ValueError('Output directory must differ from source')
    output.mkdir(parents=True, exist_ok=True)
    output.chmod(0o700)
    stages, all_checks = [], []
    for path in sorted(source.glob('[0-9][0-9]-*.sql')):
        statements, checks = [], []
        for original in split_sql(path.read_text()):
            adapted, found = adapt_statement(original)
            statements.extend(adapted)
            checks.extend(found)
        # The checksum identifies the adapted artifact before filling its own audit value.
        canonical = json.dumps(statements, ensure_ascii=False, separators=(',', ':'))
        checksum = hashlib.sha256(canonical.encode()).hexdigest()
        statements = [re.sub(r"(INSERT INTO schema_migrations[\s\S]*?,\s*)'[a-f0-9]{64}'", lambda m: m.group(1) + "'" + checksum + "'", sql) for sql in statements]
        payload = {'file': path.name, 'checksum': checksum, 'statements': statements, 'checks': checks}
        target = output / (path.stem + '.json')
        target.write_text(json.dumps(payload, ensure_ascii=False, indent=2))
        target.chmod(0o600)
        # DELIMITER is a mysql-client directive; PDO uses the JSON statements directly.
        readable = []
        for sql in statements:
            readable.append('DELIMITER $$\n' + sql + '$$\nDELIMITER ;' if sql.startswith('CREATE TRIGGER') else sql + ';')
        sql_path = output / path.name
        sql_path.write_text('\n\n'.join(readable) + '\n')
        sql_path.chmod(0o600)
        stages.append({'file': target.name, 'checksum': checksum, 'statements': len(statements)})
        all_checks.extend(checks)
    if not stages:
        raise ValueError('No numbered SQL files found')
    verification = {}
    for path in sorted(source.glob('*.verify.sql')):
        queries = []
        for query in split_sql(path.read_text()):
            if not query.startswith('SELECT'):
                raise ValueError('Verification must be read-only: ' + path.name)
            if 'information_schema.check_constraints' in query.lower():
                # MySQL 5.7 exposes the equivalent validation in TRIGGERS.
                query = "SELECT TRIGGER_NAME, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='landing_lead' ORDER BY TRIGGER_NAME"
            queries.append(query)
        verification[path.name] = queries
    manifest = output / 'manifest.json'
    manifest.write_text(json.dumps({'mysql': '5.7', 'collation': 'utf8mb4_unicode_ci', 'stages': stages, 'checks': all_checks, 'verification': verification}, indent=2))
    manifest.chmod(0o600)
    return stages, all_checks


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source', type=Path, help='Private directory containing numbered bootstrap/migration SQL')
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    stages, checks = prepare(args.source, args.output)
    print(f'Prepared {len(stages)} stages, {len(checks)} checks and {len(checks) * 2} triggers. No SQL executed.')

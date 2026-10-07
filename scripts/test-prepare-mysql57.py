#!/usr/bin/env python3
"""Database-free regression tests for the shared-hosting SQL adapter."""
import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('adapter', Path(__file__).with_name('prepare-mysql57.py'))
adapter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(adapter)


class AdapterTests(unittest.TestCase):
    def test_comments_and_literals_preserve_statement_boundaries(self):
        statements = adapter.split_sql("-- ignored;\nINSERT INTO t VALUES ('a;b', 'it''s;ok'); /* ; */ SELECT 1;")
        self.assertEqual(len(statements), 2)
        self.assertIn("'it''s;ok'", statements[0])
        self.assertEqual(statements[1], 'SELECT 1')

    def test_nullable_check_uses_sql_three_valued_logic(self):
        statements, checks = adapter.adapt_statement("CREATE TABLE t (id int, status varchar(10), CONSTRAINT t_ck CHECK (status IS NULL OR status IN ('IN', 'OR'))) ENGINE=InnoDB COLLATE=utf8mb4_0900_ai_ci")
        self.assertEqual(len(checks), 1)
        self.assertNotIn('CHECK (', statements[0])
        self.assertIn('utf8mb4_unicode_ci', statements[0])
        self.assertIn("(NEW.status IS NULL OR NEW.status IN ('IN', 'OR')) IS FALSE", statements[1])
        self.assertIn('BEFORE INSERT', statements[1])
        self.assertIn('BEFORE UPDATE', statements[2])

    def test_alter_keeps_column_and_enforces_check(self):
        statements, checks = adapter.adapt_statement("ALTER TABLE financial_entry ADD COLUMN payment_method varchar(20) NULL, ADD CONSTRAINT payment_ck CHECK (payment_method IS NULL OR payment_method IN ('cash', 'pix'))")
        self.assertEqual(len(checks), 1)
        self.assertEqual(statements[0], 'ALTER TABLE financial_entry ADD COLUMN payment_method varchar(20) NULL')
        self.assertIn('ON `financial_entry`', statements[1])

    def test_arithmetic_qualifies_both_columns(self):
        self.assertEqual(adapter.new_expression('total = subtotal - discount'), 'NEW.total = NEW.subtotal - NEW.discount')

    def test_base_tables_have_explicit_engine_and_charset(self):
        statements, _ = adapter.adapt_statement('CREATE TABLE t (id int PRIMARY KEY)')
        self.assertTrue(statements[0].endswith('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'))

    def test_unsupported_check_fails_instead_of_being_ignored(self):
        with self.assertRaises(ValueError):
            adapter.adapt_statement('CREATE TABLE t (id int CHECK (id > 0))')


if __name__ == '__main__':
    unittest.main()

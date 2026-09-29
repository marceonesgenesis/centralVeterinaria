# Platão — contratos e adapters

subagent_type: general-purpose
model: herdado

## Contexto
Implementar os dois adapters de apoio da Central de Atendimento — o assistente de IA (placeholder, sem provedor real) e os documentos do encounter (reaproveitando o storage já existente) — que `EncounterView` (T-06) vai consumir.

## Tasks atribuídas
- T-04: assistente de IA — implementação placeholder.
- T-05: documentos do encounter (reaproveitando storage existente).

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/04-fase-2-nucleo-clinico/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/Core/Assistant/Contract/AiClinicalAssistantInterface.php` (T-02, contrato já pronto quando você começar)
- `src/app/Core/Storage/StorageInterface.php`, `S3CompatibleStorage.php`, `ObjectKeyNamespace.php` (Fase 0 — reaproveitar, não recriar)
- `src/app/Core/Audit/NullAuditLogWriter.php` (Fase 0 — padrão de implementação "Null" a espelhar em `NullAiClinicalAssistant`)

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- `NullAiClinicalAssistant` não pode fazer nenhuma chamada de rede nem depender de provedor externo.
- `EncounterDocumentService` não pode criar nenhuma tabela nova — usa `stored_object`/`StorageInterface` já existentes.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">

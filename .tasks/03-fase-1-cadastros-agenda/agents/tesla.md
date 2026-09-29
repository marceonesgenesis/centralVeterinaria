# Tesla — agenda, fila e busca

subagent_type: general-purpose
model: herdado

## Contexto
Construir as telas mais interativas do fluxo crítico de recepção — Agenda, Fila de atendimento e Busca inicial — seguindo os mocks 03 e 04 (https://claude.ai/artifact/A78u8yLNxCro1AYHRLDrcR).

## Tasks atribuídas
- T-12: Tela Adianti de Agenda.
- T-13: Tela Adianti de Fila de atendimento.
- T-14: Busca inicial (global).

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/03-fase-1-cadastros-agenda/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/admin/SystemUnitForm.php` (padrão `TStandardForm` a replicar)
- Mocks de referência: artboards 03 (Agenda) e 04 (Fila) em https://claude.ai/artifact/A78u8yLNxCro1AYHRLDrcR
- `src/app/Core/Application/AppointmentService.php`, `QueueEntryService.php`, `TutorService.php`, `PatientService.php` (T-07/T-08/T-04/T-05 — ler apenas a assinatura pública, não modificar)

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas — em especial, **não editar `src/menu.xml`** (fica para T-15).
- Respeitar literalmente os tokens de "Consome": chamar o Application service correspondente, nunca acessar `Persistence`/`Domain` diretamente do controller.
- Conflito de horário e transição de status inválida devolvidos pelo Application service devem virar mensagem tratada na tela, nunca erro fatal.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">

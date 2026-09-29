# Aang — tela única (integração Core ↔ Adianti)

subagent_type: general-purpose
model: herdado

## Contexto
Construir a Central de Atendimento — a tela única de consulta clínica descrita no PRD (seção 8.8) e já mockada em https://claude.ai/artifact/A78u8yLNxCro1AYHRLDrcR (artboard "Atendimento") — consumindo os Application services do Core sem duplicar regra de negócio no controller.

## Tasks atribuídas
- T-06: Tela única de Atendimento (EncounterView).

Leia a especificação completa da task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/04-fase-2-nucleo-clinico/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/clinic/AppointmentForm.php`, `QueueEntryView.php` (Fase 1 — padrão de controller a replicar: `resolveTenantContext()`, wiring de services via `TTransaction::open('permission')`, captura de exceções específicas como `TMessage`)
- `src/lib/adianti/widget/form/TText.php` (`enableSpeechRecognition()`, já nativo — não reimplemente)
- `src/lib/adianti/include/components/tentry/tentry.js` (implementação real da Web Speech API por trás do método acima)
- Mock de referência: artboard "Atendimento" em https://claude.ai/artifact/A78u8yLNxCro1AYHRLDrcR
- `src/app/Core/Application/AppointmentService.php` (Fase 1 — para a ação "Retorno")
- `src/app/Core/Application/ServiceCatalogService.php` (Fase 1 — para o preço no resumo financeiro)

## Restrições
- Não editar arquivos fora dos listados na task — em especial, **não editar `src/menu.xml`** (fica para T-07).
- Respeitar literalmente os tokens de "Consome": chamar o Application service correspondente, nunca acessar `Persistence`/`Domain` diretamente do controller.
- Prescrição/exame/procedimento/vacina ficam só como casca de UI (painel abre, sem gravar em tabela própria) — não crie nenhuma entidade nova pra isso.
- Autosave e ditado por voz são funcionalidades reais, não decorativas — teste que realmente persistem/transcrevem.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">

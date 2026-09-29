# Aang — telas e integração com a Central de Atendimento

subagent_type: general-purpose
model: herdado

## Contexto
Construir a tela de Prescrição e, no final, conectar as 3 ações inline já existentes em `EncounterView` (Fase 2) às telas dedicadas novas — sem editar `EncounterView` em paralelo com mais ninguém.

## Tasks atribuídas
- T-06: Tela de Prescrição (formulário + PDF).
- T-09: Conectar EncounterView às 3 telas novas (só depois de T-06/T-07/T-08 estarem prontas).

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/05-fase-3-prescricao-exames-vacinas/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/admin/SystemUnitForm.php` (padrão TStandardForm a replicar)
- `src/app/control/clinic/EncounterView.php` (Fase 2 — arquivo que T-09 vai editar; leia inteiro antes de mexer)
- `src/composer.json` (dompdf/pdfdesigner já instalados, para o PDF de T-06)

## Restrições
- Não editar `src/menu.xml` (fica para T-10).
- T-09 só pode rodar depois que T-06/T-07/T-08 (as 3 telas de destino) já existirem — confirme que os arquivos existem antes de editar `EncounterView`.
- T-09 só pode trocar o destino dos 3 botões já existentes — nenhuma regra de negócio nova em `EncounterView`.
- Respeitar literalmente os tokens de "Consome": chamar o Application service correspondente, nunca acessar `Persistence`/`Domain` diretamente do controller.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">

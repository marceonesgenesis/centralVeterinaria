# Aang — telas de cadastro (integração Core ↔ Adianti)

subagent_type: general-purpose
model: herdado

## Contexto
Construir as telas Adianti de Tutor, Paciente e Serviço, consumindo os Application services do Core sem duplicar regra de negócio no controller — seguindo o fluxo aprovado nos mocks 01 e 02 (https://claude.ai/artifact/A78u8yLNxCro1AYHRLDrcR).

## Tasks atribuídas
- T-09: Tela Adianti de Tutor (Form/List).
- T-10: Tela Adianti de Paciente (Form/List).
- T-11: Tela Adianti de Serviço (Form/List).

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/03-fase-1-cadastros-agenda/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/admin/SystemUnitForm.php`, `SystemUnitList.php` (padrão `TStandardForm`/`TStandardList` a replicar)
- `src/app/model/admin/SystemUnit.php` (padrão `TRecord` com `addAttribute()`)
- `.docs/design-system.md`, `/design-system.html` (tokens `cv-` a reaproveitar no que o Adianti permitir customizar)
- Mocks de referência: artboards 01 (Tutor) e 02 (Paciente) em https://claude.ai/artifact/A78u8yLNxCro1AYHRLDrcR

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas — em especial, **não editar `src/menu.xml`** (fica para T-15).
- Respeitar literalmente os tokens de "Consome": chamar o Application service correspondente, nunca acessar `Persistence`/`Domain` diretamente do controller.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">

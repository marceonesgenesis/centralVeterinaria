# Jano — menu e registro de telas

subagent_type: general-purpose
model: herdado

## Contexto
Depois que as 2 telas novas de pendência existem (T-03/T-04) e as outras tasks fecham a jornada correta, `menu.xml` precisa parar de expor 9 itens que só levam a becos sem saída, e as 2 telas novas precisam ser registradas em `system_program`/`system_group_program` para ficarem acessíveis e autorizadas.

## Tasks atribuídas
- T-06: `menu.xml` — substituir 2 itens, remover 9.
- T-07: DML de registro das 2 telas novas.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/09-navegacao-jornada-clinica/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/menu.xml` (linhas aproximadas de cada item já levantadas em `tasks.md`/T-06 — confirmar o número exato antes de editar, o arquivo pode ter mudado de posição)
- Registros existentes em `system_program`/`system_group_program` para telas já registradas em fases anteriores (ex.: `PayableList`, `ExamResultForm`) — usar como modelo exato de formato/colunas.

## Restrições
- Esta onda só abre depois que T-03 e T-04 (Hermes) estiverem `[x]` em `tasks.md` — as classes `PendingExamResultList`/`PendingReceivableList` precisam existir em disco antes de qualquer referência a elas.
- T-07 é DML: aplicar o procedimento de sempre (SQL exato mostrado, efeito e risco resumidos, autorização explícita do usuário pedida imediatamente antes de executar — nunca considerar a aprovação do plano como autorização para o DML em si). Sem essa autorização específica, documentar como bloqueio em `notes.md` e não executar.
- Nenhuma migration, nenhuma mudança de schema — só DML de registro, igual toda fase anterior.
- Não remover `PatientList`/`PatientForm`/etc. do sistema — só o `<menuitem>` em `menu.xml`; as classes continuam existindo e acessíveis via link contextual (T-05, T-03, T-04, EncounterView já existente).
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">

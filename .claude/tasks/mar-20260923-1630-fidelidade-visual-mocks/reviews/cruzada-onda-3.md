## Re-validação 1
- ok: php -m no container app → calendar presente
- ok: SystemLogDashboard → cards 3/0/0,00/0, "No logs", sem erro; console 0 erros; requisições 200
- ok: SystemPHPErrorLogView → "A localização atual do log de erros é /proc/self/fd/2", estado vazio sem erro; console 0 erros
- ok (parcial): TutorList, PatientList, QueueEntryView (engine.php) → 10 li.page-item.off no HTML, regra carregada `.tpagenavigation .pagination > li.page-item.off { display: none; }`; sem Fatal/Warning
- [não rodado] validação visual renderizada (offsetParent/screenshot) das 3 listas paginadas e QueueEntryView com dados; menu Configurações → Logs clicado não foi percorrido (URLs diretas)
- [ruling] escopo: b9ea10c e dd4efaa com trailer "Task: cruzada-onda-3", não T-NN
- ok: escopo f300a8b..HEAD só nos arquivos das correções e .claude/tasks

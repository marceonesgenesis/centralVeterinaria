# Relatório — cruzada-onda-6

- Status: concluído
- Agente: Tesla · model inherit
- Onda: 6 (correção cruzada, telas de T-18)

## Perguntas
- nenhuma

## RED
sem teste: controllers Adianti fora do autoload PSR-4 do runner. A reprodução foi feita em CLI no container (`init.php`, sessão simulada: logged/userid=1/tenantid=1/userunitid=1; sem escrita: onSave sem account_id válido, onClose sem closing_balance), com o script `scratchpad/repro.php`. Base: 0df204b.
```text
(1) POST engine.php?class=EncounterAccountForm&method=onSave (o form só envia account_id)
(1) empty-state: 1                                              # "Informe um encounter_id válido..."
(1) reload: index.php?class=EncounterAccountForm&encounter_id=  # encounter_id vazio
    causa: new TAction([$this,'onSave'|'onApplyDiscount'|'onClose']) sem parâmetros; paramInt() não acha encounter_id; reloadSelf() usa $this->encounterId nulo
(2) CashSessionForm.php:181  CvFormat::e($payment_method . ': ' . CvFormat::money(...))  → "cash: R$ 130,00"
    (sessão 113 aberta no dev ainda não tem pagamento; reprodução só pelo código)
(3) CashSessionForm&method=onClose: construtor → onReload() + TPage::run → onClose() → onReload()
(3) headers renderizados: 2 | page children: 2                  # painel anterior empilhado
```
Commit RED: sem teste: controllers Adianti fora do autoload do runner; reprodução em CLI acima

## Arquivos tocados
- src/app/control/clinic/EncounterAccountForm.php — modificado: as 3 TAction levam `['encounter_id' => $this->encounterId]` (os nomes de $action ficam iguais); reloadSelf() cai para `$this->account->encounterId()` se o encounterId vier nulo
- src/app/control/clinic/CashSessionForm.php — modificado: `CvFormat::paymentMethod()` no total por forma de pagamento; `onReload()` roda `$this->clearChildren()` antes de `parent::add()`

Commit: d006c24 (Task: cruzada-onda-6)

## Evidência
```text
(1) TAction serialize:
onSave → index.php?class=EncounterAccountForm&method=onSave&encounter_id=1547
onApplyDiscount → index.php?class=EncounterAccountForm&method=onApplyDiscount&encounter_id=1547
onClose → index.php?class=EncounterAccountForm&method=onClose&encounter_id=1547
paramInt → 1547
reload → index.php?class=EncounterAccountForm&encounter_id=1547
(2) CvFormat::paymentMethod("cash") . ": " . CvFormat::money(13000) → Dinheiro: R$ 130,00
(3) headers renderizados: 1 | page children: 1
php -l → No syntax errors detected (2 arquivos)
php tests/run.php → Total: 182, Passed: 182, Failed: 0
```
Não construí a tela com encounter_id=1547 real para não disparar openOrGet/syncAutomaticItems (escrita) fora do gate.

## Desvios do plano
- A correção também cobre "Aplicar desconto", que tinha a mesma causa (TAction sem encounter_id). O nome da $action continua o mesmo.

## Pendências
- GATE (validador, navegador): EncounterAccountForm&encounter_id=1547 → Adicionar item manual / Fechar conta voltam a `encounter_id=1547` sem o aviso; CashSessionForm mostra "Dinheiro: R$ …" e fica com um painel só depois de Abrir/Fechar.

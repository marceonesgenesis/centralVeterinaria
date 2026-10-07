# final-fix: combo de profissional sem filtro de tenant (bloqueante da revisão final)

Status: concluído. Commits: `dbe7318` (RED) e `12945fa` (GREEN), ambos com o trailer `Task: final-fix`.

## Problema
`new TDBCombo('professional_system_user_id', 'permission', 'SystemUser', 'id', 'name', 'name')` aparecia sem critério em ExamRequestForm, PrescriptionForm, ProcedureExecutionForm e VaccinationForm. O combo listava usuários de todos os tenants. Nenhum dos quatro serviços (Exam/Prescription/ProcedureExecution/VaccinationService) validava `professional_system_user_id`: o valor era só convertido para `(int)` e persistido.

## Correção
- **Helper de apresentação** `src/app/lib/util/CvTenantUsers.php`: `criteria(callable $resolveTenantContext)` e `combo($name, $resolver)`. O SQL foi extraído literalmente de `EncounterAccountForm::tenantUsersCriteria()`: `active='Y'` + `id IN (SELECT system_user_id FROM tenant_user WHERE tenant_id = N)`. Qualquer Exception vira tenant 0 (fail-closed). A lógica vale para os 5 usos:
  - os 4 forms passam a usar `CvTenantUsers::combo('professional_system_user_id', static fn () => self::resolveTenantContext())`;
  - `EncounterAccountForm::tenantUsersCriteria()` agora só delega ao helper, e o comportamento não mudou (evidência abaixo).
- **Core (validação no save)**:
  - contrato `Domain/Contract/TenantUserDirectoryInterface::isActiveMember(int): bool`;
  - implementação PDO `Persistence/TenantUserDirectory`, com a mesma regra do combo e o tenant vindo do TenantContext;
  - os 4 serviços recebem o diretório como último argumento do construtor e lançam `CrossTenantReferenceException("professional_system_user_id N was not found for the authenticated tenant")`. O lançamento acontece depois das checagens de encounter e catálogo e antes da autorização e de qualquer escrita. Os 4 forms já tratavam essa exceção com TMessage.
- **Wiring**: além dos 4 forms, as outras construções dos serviços também foram atualizadas: PendingExamResultList, ExamResultForm e VaccinationCardView.
- **Testes**:
  - `tests/Support/FakeTenantUserDirectory`;
  - um teste novo por serviço: com o profissional 999 fora do tenant, a exceção é lançada, nada é persistido e nenhum estoque é baixado;
  - os testes existentes passam `FakeTenantUserDirectory::allowingAll()`.

## Evidência
- RED (em `dbe7318`, `php tests/run.php`): `Total: 186, Passed: 182, Failed: 4`. As 4 falhas são `Expected exception ...CrossTenantReferenceException was not thrown`.
- GREEN: `php tests/run.php` → `Total: 186, Passed: 186, Failed: 0, Skipped: 0`.
- `php -l` nos 20 arquivos tocados → `No syntax errors detected` em todos.
- Smoke de render em CLI (read-only, sessão simulada user 1 / unit 1). Cada form foi renderizado com `show()`, e as opções do `<select name="professional_system_user_id">` foram comparadas com `SELECT u.id FROM system_users u JOIN tenant_user tu ... WHERE tu.tenant_id=:t AND u.active='Y'`:
  ```
  tenants: 1
  tenant=1 ExamRequestForm          combo=[1] select=[1] MATCH
  tenant=1 PrescriptionForm         combo=[1] select=[1] MATCH
  tenant=1 ProcedureExecutionForm   combo=[1] select=[1] MATCH
  tenant=1 VaccinationForm          combo=[1] select=[1] MATCH
  tenant=2 ExamRequestForm          combo=[] select=[] MATCH
  tenant=2 PrescriptionForm         combo=[] select=[] MATCH
  tenant=2 ProcedureExecutionForm   combo=[] select=[] MATCH
  tenant=2 VaccinationForm          combo=[] select=[] MATCH
  sem sessão ExamRequestForm combo=[]
  TenantUserDirectory tenant=1 user=1 => true
  TenantUserDirectory tenant=1 user=999 => false
  TenantUserDirectory tenant=2 user=1 => false
  ```
  Antes da correção, o combo sem critério mostraria `[1]` também na sessão de tenant 2.
- EncounterAccountForm sem mudança de comportamento. `tenantUsersCriteria()` foi invocado via Reflection e o `dump()` saiu idêntico ao SQL original:
  - tenant 1: `(active = 'Y' AND id IN (SELECT system_user_id FROM tenant_user WHERE tenant_id = 1))` → ids [1];
  - tenant 2: ids [];
  - sem sessão: `tenant_id = 0` → ids [].
- Nenhum DML foi executado. Scripts do smoke: scratchpad da sessão (`smoke.php`, `smoke3.php`), passados ao container via stdin.

## Limitações e pendências
- O banco tem só o tenant 1 e um usuário, por isso o smoke não mostra um usuário de outro tenant sendo filtrado com dados reais. Esse cenário é coberto pelos testes unitários e pelo `TenantUserDirectory` com tenant 2. Criar dados exigiria DML autorizado.
- A regra ignora `tenant_user.status` (invited/inactive), igual ao filtro original de EncounterAccountForm. Mantido de propósito para não mudar comportamento. Se `status='active'` virar exigência, basta ajustar o helper e o TenantUserDirectory juntos.
- Fora do escopo: `EncounterAccountService` também não valida `authorized_by_system_user_id` do desconto no save (o combo filtra, o serviço não). Mesmo padrão; candidato a follow-up.

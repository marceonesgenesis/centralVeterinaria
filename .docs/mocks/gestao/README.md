# Especificação e Mocks da Área de Gestão (Back-office) — Central Vet Pro

Documento de validação de interface e jornada administrativa anterior à codificação, atendendo às decisões de 29/10/2026 registradas em `.docs/plan/pendencias-mvp.md § 2.8`.

O protótipo interativo navegável está disponível no arquivo:
👉 [`src/gestao-mocks.html`](../../src/gestao-mocks.html)

---

## 1. Princípios Arquiteturais e de Interface

1. **Isolamento Completo de Dados Clínicos**:
   - A equipe de gestão acessa exclusivamente métricas de negócio, faturas Stripe, clientes, planos, convites e campanhas.
   - Nenhuma tela da gestão possui visualização de prontuários, tutores, pacientes ou registros médicos de clínicas clientes.
2. **Design System & Acessibilidade**:
   - Extensão do tema AdminBS5 com tokens `--cv-*`.
   - Rótulos sempre alinhados à esquerda (conforme regra estrita em `CLAUDE.md`).
   - Alvos de toque (`.cv-touch-target`) com altura mínima de 44px.
   - Semântica de alertas com `role="status"` e `role="alert"`.
3. **Segurança Reforçada**:
   - Ponto de entrada exclusivo em `gestao.php`.
   - 2FA obrigatório (TOTP) para todos os perfis internos.
   - Trilha de auditoria indelével gravando operador, IP, data/hora e o diff antes/depois.

---

## 2. Mapa das Telas Prototipadas

| # | Tela | Finalidade | Perfis Autorizados |
|---|---|---|---|
| 1 | **Login da Gestão** (`/gestao.php`) | Entrada isolada sem seleção de unidades e com 2FA TOTP | Todos da equipe de gestão |
| 2 | **Dashboard Executivo** | KPIs de MRR, retenção, clientes ativos e funil de bônus | Todos |
| 3 | **Clientes & Licenças** | Visão das clínicas, status Stripe, suspensão, troca de plano e prorrogação | Admin, Comercial (consulta), Financeiro, Suporte (consulta) |
| 4 | **Cadastro de Planos & Ofertas** | Gestão de preços mensais, Price ID Stripe, quotas e módulos ativos | Admin, Comercial (consulta), Financeiro (consulta) |
| 5 | **Convites com Bônus (Trial 15d)** | Envio de links com degustação gratuita, sem cartão, com conversão acompanhada | Admin, Comercial, Suporte (reenvio) |
| 6 | **Marketing & Campanhas** | Leads da landing, checkouts abandonados, exportação CSV e disparador com opt-out LGPD | Admin, Comercial |
| 7 | **Horários do Suporte** | Grade semanal de expediente, feriados e canais WhatsApp/E-mail | Admin, Suporte |
| 8 | **Equipe Interna (4 Perfis)** | Gestão de operadores com perfis fixos (Admin, Comercial, Financeiro, Suporte) | Admin do Sistema |
| 9 | **Trilha de Auditoria** | Histórico auditável de mutações de dados com visualização de diff | Admin, Financeiro (consulta) |

---

## 3. Matriz de Perfis e Permissões da Gestão

| Recurso / ação | Administrador | Comercial | Financeiro | Suporte |
|---|:---:|:---:|:---:|:---:|
| **Clientes & Licenças: Consultar** | ✔ | ✔ | ✔ | ✔ |
| **Suspender, Reativar, Trocar Plano, Prorrogar** | ✔ | — | ✔ | — |
| **Faturas, Estornos e Conciliação Stripe** | ✔ | — | ✔ | Consulta |
| **Cadastro de Planos e Ofertas** | ✔ | Consulta | Consulta | — |
| **Convites com Bônus: Enviar/Cancelar** | ✔ | ✔ | — | Reenviar |
| **Prazo Padrão de Bônus (15d) e Validade** | ✔ | — | — | — |
| **Marketing: Leads e Exportação CSV** | ✔ | ✔ | — | — |
| **Marketing: Disparo de Campanhas de E-mail** | ✔ | ✔ | — | — |
| **Horários de Atendimento do Suporte** | ✔ | — | — | ✔ |
| **Usuários da Equipe de Gestão** | ✔ | — | — | — |
| **Trilha de Auditoria (Diff)** | ✔ | — | Consulta | — |


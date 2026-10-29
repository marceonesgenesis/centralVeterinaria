# Piloto: runbook de operação

Como operar o piloto depois do go-live ([`piloto-go-live.md`](./piloto-go-live.md)). Sem credencial real; escrita em banco exige autorização explícita.

## Suporte

- Canal: e-mail (obrigatório, `SUPPORT_EMAIL`) e WhatsApp (opcional, `SUPPORT_WHATSAPP`).
- Horário: segunda a sexta, das 8h às 18h (`SUPPORT_HOURS`).
- As clínicas encontram o canal em "Ajuda" no app e nos textos legais.
- Prazos propostos (a confirmar com o dono do piloto): resposta inicial no mesmo dia útil para sev1 e sev2; até o próximo dia útil para sev3.

## Triagem e severidade

| Severidade | Critério | Ação |
|---|---|---|
| sev1 | Clínica sem acesso, perda ou vazamento de dados, cobrança indevida em massa | Parar o que estiver em curso, acionar o operador, comunicar a clínica, considerar rollback ([`release.md`](./release.md)) |
| sev2 | Função principal quebrada com contorno (agenda, atendimento, financeiro, PDF) | Corrigir no próximo release, comunicar o contorno |
| sev3 | Defeito cosmético, dúvida de uso, texto | Registrar e agrupar |

Para alertas automáticos e deduplicação, ver [`observabilidade.md`](./observabilidade.md).

## Comunicação com as clínicas

- Aviso prévio de manutenção e de release com impacto.
- Em sev1, primeiro contato assim que confirmado e atualização a cada 2 horas úteis (proposta, a confirmar) até a resolução; ao fechar, resumo do que houve e do que muda.
- Um responsável por clínica piloto, nomeado no início.

## Coleta de feedback

- Cadência: contato semanal com cada clínica piloto (15 minutos) e formulário curto ao fim de cada quinzena.
- Registro: uma linha por item (data, clínica, módulo, tipo defeito/ideia/dúvida, severidade, decisão) em planilha única do piloto; consolidar toda semana.

## Cancelamento e retenção

- O administrador cancela pelo app em Assinatura; o cancelamento vale no fim do período pago. Não há reativação pelo app: o suporte trata, junto ao Stripe.
- Retenção: 30 dias depois do cancelamento (`DATA_RETENTION_DAYS_AFTER_CANCEL`).
- Exportação: a clínica baixa os dados estruturados pelo app em "Exportar dados da clínica", inclusive depois de cancelar.
- Fotos e PDFs não entram na exportação: o suporte entrega a pedido.
- Exclusão: depois do prazo, manual, pelo operador. **Exige autorização** e backup recente; confirme com a clínica por escrito e registre a data. Ver [`../security/lgpd-retencao.md`](../security/lgpd-retencao.md).

## Critérios de saída do piloto

O piloto termina, e a oferta pode ser ampliada, quando:

- nenhum bloqueio aberto no escopo anunciado;
- jornada homologada nas clínicas piloto ([`piloto-homologacao.md`](./piloto-homologacao.md));
- nenhum incidente sev1 aberto;
- feedback consolidado e decisões registradas.

Módulos fora do recorte (comunicação, internação/cirurgia, IA, governança da rede) só entram via `OFFER_PREVIEW_FEATURES` depois desta decisão.

# Plano: Preparação do servidor Central Vet Pro

## Objetivo
Preparar um ambiente de desenvolvimento isolado e reproduzível para o Central Vet Pro,
alinhado ao PRD v1.1 e sem interferir nos serviços que já existem no host.

## Escopo
### Incluso
- Docker Compose dedicado com PHP 8.3-FPM, Nginx, MySQL 8 e Redis 7.
- Imagem PHP com extensões exigidas pelo PRD, incluindo GD e OPcache.
- Redes internas, volumes persistentes, healthchecks e rotação de logs.
- Arquivo de exemplo para variáveis, sem segredos, e comandos operacionais.
- Endpoint mínimo de saúde e validação automatizada do ambiente.
- Documentação de operação, backup e restauração em desenvolvimento.

### Excluído
- Aplicação funcional, schema de negócio e implementação do Adianti.
- Exposição pública, domínio, DNS, TLS e mudanças no firewall.
- Credenciais e integrações reais (S3/R2, WhatsApp, e-mail, billing, IA).
- MCP, AI Gateway, PDF service e pipeline de produção nesta primeira etapa.

## Decisões de arquitetura
| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Stack isolada em Docker Compose | Serviços instalados diretamente no host | Evita conflito com MySQL, Redis, Nginx e portas de outros projetos |
| Publicar somente HTTP em `127.0.0.1:8081` | Usar porta 80 ou expor externamente | Porta 80 já está ocupada e acesso local reduz superfície de ataque |
| MySQL e Redis apenas em rede interna | Publicar 3306/6379 | O app acessa os serviços sem expô-los no host |
| Volumes nomeados e backup explícito | Dados efêmeros | Preserva dados entre recriações e permite restauração testável |
| MinIO adiado | Subir storage local agora | Evita consumo de disco antes da decisão S3/R2/MinIO |

## Diagrama de dependências
T-01 → T-02 → T-03 → T-04
                    T-03 → T-05
                            T-04 → T-06
                            T-05 → T-06

## Agentes
| Agente | subagent_type | Tasks | Pode paralelizar com |
|---|---|---|---|
| Sun Tzu (orquestrador) | — | todas | — |
| Athena | explorer | T-01 | — |
| Aang | general-purpose | T-02, T-03, T-04, T-05 | — |
| Levi | reviewer | T-06 | — |


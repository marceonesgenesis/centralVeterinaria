<?php

declare(strict_types=1);

/*
 * S6: endpoint genérico de download desabilitado.
 *
 * Responde 404 com corpo vazio a qualquer requisição, antes de carregar o
 * framework e sem tocar no parâmetro recebido. O caminho pedido não é
 * registrado no log. Mapa dos usos e motivo em docs/security/downloads-s6.md.
 */

http_response_code(404);
error_log('download.disabled');

return;

<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\FeriasService;

/**
 * Liga e desliga as férias agendadas antes de a rota ler rankings (o api.php faz isso no começo de toda chamada; aqui só as rotas
 * que leem ranking pedem). Nome na rota: 'ferias'.
 */
final class SincronizarFerias implements Middleware
{
    public function __construct(private FeriasService $ferias)
    {
    }

    public function handle(Request $requisicao, callable $proximo, array $argumentos): Response
    {
        $this->ferias->sincronizar();
        return $proximo($requisicao);
    }
}

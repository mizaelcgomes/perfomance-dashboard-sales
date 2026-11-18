<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Musica\DefinirProximaMusica;
use App\Actions\Musica\EnviarMusica;
use App\Actions\Musica\ExcluirMusica;
use App\Core\Request;
use App\Core\Response;
use App\Services\MusicaService;

/** Playlist de música de fundo da TV: a TV lista (público); enviar, apagar e escolher a próxima exigem login. */
final class MusicaController
{
    public function __construct(
        private MusicaService $musicas,
        private EnviarMusica $enviar,
        private ExcluirMusica $excluir,
        private DefinirProximaMusica $definirProxima
    ) {
    }

    /** @param array<string, string> $p */
    public function listar(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'data' => $this->musicas->listarFundo()]);
    }

    /** Campo: music (arquivo MP3 de até 25 MB). @param array<string, string> $p */
    public function enviar(Request $r, array $p = []): Response
    {
        return $this->enviar->executar($r->arquivo('music'))->paraResponse(200);
    }

    /** Campo: name (nome do arquivo). @param array<string, string> $p */
    public function excluir(Request $r, array $p = []): Response
    {
        return $this->excluir->executar((string) $r->corpo('name', ''))->paraResponse(200);
    }

    /** Campo: name (nome do arquivo). @param array<string, string> $p */
    public function definirProxima(Request $r, array $p = []): Response
    {
        return $this->definirProxima->executar((string) $r->corpo('name', ''))->paraResponse(200);
    }
}

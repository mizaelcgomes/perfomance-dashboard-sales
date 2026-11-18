<?php
declare(strict_types=1);

namespace App\Actions\Musica;

use App\Core\Resultado;
use App\Repositories\RotacaoRepository;
use App\Services\MusicaService;

/** Caso de uso: mandar uma música da playlist tocar a seguir no painel da TV. */
final class DefinirProximaMusica
{
    public function __construct(private MusicaService $musicas, private RotacaoRepository $rotacao)
    {
    }

    public function executar(string $nome): Resultado
    {
        $nome = basename($nome);
        if (!$this->musicas->nomeValido($nome)) {
            return Resultado::falha('Arquivo inválido');
        }
        if (!$this->musicas->existeNoFundo($nome)) {
            return Resultado::falha('Arquivo não encontrado na pasta');
        }
        $this->rotacao->definirProximaMusica($nome);
        return Resultado::ok([], 'Música enviada para o painel');
    }
}

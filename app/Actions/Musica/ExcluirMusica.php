<?php
declare(strict_types=1);

namespace App\Actions\Musica;

use App\Core\Resultado;
use App\Repositories\RotacaoRepository;
use App\Services\MusicaService;

/** Caso de uso: apagar uma música da playlist de fundo (se era a "próxima", a escolha é limpa). */
final class ExcluirMusica
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
        $this->musicas->removerDoFundo($nome);
        if ($this->rotacao->proximaMusica() === $nome) {
            $this->rotacao->definirProximaMusica('');
        }
        return Resultado::ok([], 'Música removida');
    }
}

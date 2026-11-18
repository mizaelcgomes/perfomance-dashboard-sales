<?php
declare(strict_types=1);

namespace App\Actions\Equipe;

use App\Core\Resultado;
use App\Repositories\EquipeRepository;
use App\Services\ImagemInvalidaException;
use App\Services\ImagemService;

/** Caso de uso: criar uma equipe (nome, cor, foto e, se vierem, as metas fixas antigas, que a aba Metas & Regras já não pede). */
final class AdicionarEquipe
{
    public function __construct(private EquipeRepository $equipes, private ImagemService $imagens)
    {
    }

    /** @param mixed $nome @param mixed $cor @param mixed $metaSemanal @param mixed $metaMensal @param mixed $ocultaTv 1 = a TV não mostra a equipe nem os closers dela @param array<string, mixed>|null $foto arquivo enviado (campo "foto") */
    public function executar($nome, $cor, $metaSemanal, $metaMensal, $ocultaTv, ?array $foto): Resultado
    {
        $nome = trim(is_scalar($nome) ? (string) $nome : '');
        $cor = is_scalar($cor) ? (string) $cor : '#d4af37';
        if (!$nome) {
            return Resultado::falha('Nome obrigatório');
        }
        try {
            $imagem = $this->imagens->enviar($foto);
        } catch (ImagemInvalidaException $erro) {
            return Resultado::falha('Erro: ' . $erro->getMessage());
        }
        $id = $this->equipes->criar($nome, $cor, $imagem, max(0, (int) (is_scalar($metaSemanal) ? $metaSemanal : 0)), max(0, (int) (is_scalar($metaMensal) ? $metaMensal : 0)), (int) (bool) (is_scalar($ocultaTv) ? $ocultaTv : 0));
        return Resultado::ok(['id' => $id], 'Equipe criada');
    }
}

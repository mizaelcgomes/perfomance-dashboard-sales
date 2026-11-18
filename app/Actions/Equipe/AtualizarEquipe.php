<?php
declare(strict_types=1);

namespace App\Actions\Equipe;

use App\Core\Resultado;
use App\Repositories\EquipeRepository;
use App\Services\ImagemInvalidaException;
use App\Services\ImagemService;

/**
 * Caso de uso: editar uma equipe. As metas fixas antigas só mudam se vierem no envio (o formulário não as manda mais: elas ficam em Metas &
 * Regras). O líder precisa ser closer ativo DESTA equipe; qualquer outra coisa vira "sem líder".
 */
final class AtualizarEquipe
{
    public function __construct(private EquipeRepository $equipes, private ImagemService $imagens)
    {
    }

    /** @param mixed $id @param mixed $nome @param mixed $cor @param mixed $metaSemanal @param mixed $metaMensal @param mixed $liderId @param mixed $ocultaTv null ou '' = não muda; 1 = a TV não mostra a equipe nem os closers dela; 0 = mostra @param array<string, mixed>|null $foto */
    public function executar($id, $nome, $cor, $metaSemanal, $metaMensal, $liderId, $ocultaTv, ?array $foto): Resultado
    {
        $id = (int) (is_scalar($id) ? $id : 0);
        $nome = trim(is_scalar($nome) ? (string) $nome : '');
        $cor = is_scalar($cor) ? (string) $cor : '#d4af37';
        $temSemanal = $metaSemanal !== null && $metaSemanal !== '';
        $temMensal = $metaMensal !== null && $metaMensal !== '';
        if (!$id || !$nome) {
            return Resultado::falha('Dados inválidos');
        }
        $atual = $this->equipes->paraEdicao($id);
        if (!$atual) {
            return Resultado::falha('Equipe não encontrada');
        }
        $lider = (int) (is_scalar($liderId) ? $liderId : 0);
        if ($lider > 0 && !$this->equipes->ehMembroAtivo($lider, $id)) {
            $lider = 0;
        }
        try {
            $imagem = $this->imagens->enviar($foto, $atual['img_url']);
        } catch (ImagemInvalidaException $erro) {
            return Resultado::falha('Erro: ' . $erro->getMessage());
        }
        $semanal = $temSemanal ? max(0, (int) $metaSemanal) : (int) $atual['meta_semanal'];
        $mensal = $temMensal ? max(0, (int) $metaMensal) : (int) $atual['meta_mensal'];
        $oculta = ($ocultaTv !== null && $ocultaTv !== '') ? (int) (bool) $ocultaTv : (int) $atual['oculta_tv'];
        $this->equipes->atualizar($id, $nome, $cor, $imagem, $semanal, $mensal, $lider ?: null, $oculta);
        return Resultado::ok([], 'Equipe atualizada');
    }
}

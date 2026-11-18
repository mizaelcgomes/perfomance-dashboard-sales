<?php
declare(strict_types=1);

namespace App\Actions\Vendedor;

use App\Core\Resultado;
use App\Repositories\VendedorRepository;
use App\Services\ImagemInvalidaException;
use App\Services\ImagemService;

/** Caso de uso: editar um closer ativo (nome, cor, equipe e foto). As metas só mudam se vierem no envio: o formulário não as manda mais. */
final class AtualizarVendedor
{
    public function __construct(private VendedorRepository $vendedores, private ImagemService $imagens)
    {
    }

    /** @param mixed $id @param mixed $nome @param mixed $cor @param mixed $equipeId @param mixed $metas @param mixed $metaMensal @param array<string, mixed>|null $foto */
    public function executar($id, $nome, $cor, $equipeId, $metas, $metaMensal, ?array $foto): Resultado
    {
        $id = (int) (is_scalar($id) ? $id : 0);
        $nome = trim(is_scalar($nome) ? (string) $nome : '');
        $cor = is_scalar($cor) ? (string) $cor : '#d4af37';
        $equipe = $equipeId !== null && $equipeId !== '' ? (int) $equipeId : null;
        if (!$id || !$nome) {
            return Resultado::falha('Dados inválidos');
        }
        $atual = $this->vendedores->ativoParaEdicao($id);
        if (!$atual) {
            return Resultado::falha('Closer não encontrado');
        }
        if ($this->vendedores->nomeEmUso($nome, $id)) {
            return Resultado::falha('Nome já em uso');
        }
        try {
            $imagem = $this->imagens->enviar($foto, $atual['img_url']);
        } catch (ImagemInvalidaException $erro) {
            return Resultado::falha('Erro: ' . $erro->getMessage());
        }
        $avatar = $imagem ? null : ($atual['img_pravatar'] ?? random_int(1, 70));
        $metaSemanal = ($metas !== null && $metas !== '') ? max(1, (int) $metas) : (int) $atual['metas'];
        $metaDoMes = ($metaMensal !== null && $metaMensal !== '') ? max(0, (int) $metaMensal) : (int) $atual['meta_mensal'];
        $this->vendedores->atualizar($id, $equipe, $nome, $metaSemanal, $metaDoMes, $cor, $imagem, $avatar === null ? null : (int) $avatar);
        return Resultado::ok([], 'Closer atualizado');
    }
}

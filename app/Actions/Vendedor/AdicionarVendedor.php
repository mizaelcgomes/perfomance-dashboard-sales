<?php
declare(strict_types=1);

namespace App\Actions\Vendedor;

use App\Core\Resultado;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\VendedorRepository;
use App\Services\ImagemInvalidaException;
use App\Services\ImagemService;
use App\Services\MetasService;

/**
 * Caso de uso: cadastrar um closer. O cadastro não pede meta: o closer novo nasce NO PADRÃO (a meta que a maioria já tem, e nos meses com metas
 * por período o padrão de cada mês), para não aparecer como exceção em Metas & Regras. As metas só são usadas se vierem no envio. Cada dia da
 * semana de contratos nasce zerado.
 */
final class AdicionarVendedor
{
    public function __construct(
        private VendedorRepository $vendedores,
        private MetasService $metas,
        private ImagemService $imagens,
        private ConfiguracaoRepository $config
    ) {
    }

    /** @param mixed $nome @param mixed $metas @param mixed $metaMensal @param mixed $cor @param mixed $equipeId @param array<string, mixed>|null $foto */
    public function executar($nome, $metas, $metaMensal, $cor, $equipeId, ?array $foto): Resultado
    {
        $nome = trim(is_scalar($nome) ? (string) $nome : '');
        $heranca = ($metas !== null && $metas !== '') ? null : $this->metas->heranca();   // antes de criar: o padrão não pode contar com o closer novo
        $metaSemanal = $heranca ? $heranca['metas'] : max(1, (int) $metas);
        $metaDoMes = $heranca ? $heranca['mensal'] : max(0, (int) ($metaMensal ?? 0));
        $cor = is_scalar($cor) ? (string) $cor : '#d4af37';
        $equipe = !empty($equipeId) ? (int) $equipeId : null;
        $dias = $this->config->diasDaSemana();
        if (!$nome) {
            return Resultado::falha('Nome obrigatório');
        }
        if ($this->vendedores->nomeEmUso($nome)) {
            return Resultado::falha('Vendedor já existe');
        }
        try {
            $imagem = $this->imagens->enviar($foto);
        } catch (ImagemInvalidaException $erro) {
            return Resultado::falha('Erro: ' . $erro->getMessage());
        }
        $avatar = $imagem ? null : random_int(1, 70);
        $id = $this->vendedores->criar($equipe, $nome, $metaSemanal, $metaDoMes, $cor, $imagem, $avatar);
        $this->vendedores->criarDiasDaSemana($id, $dias);
        if ($heranca) {
            $this->metas->aplicarHeranca($id, $heranca);
        }
        return Resultado::ok([], 'Closer adicionado com sucesso');
    }
}

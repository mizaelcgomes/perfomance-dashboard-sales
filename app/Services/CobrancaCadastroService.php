<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Resultado;
use App\Repositories\CobrancaCadastroRepository;
use App\Repositories\VendedorRepository;

/**
 * Cobranças de cadastramento: o líder registra que COBROU um closer da equipe. O cadastramento em si acontece fora do sistema; aqui
 * não há lista de ajuda, prazo nem controle de pendência (decisão do cliente). Criar e editar a observação; não há exclusão.
 */
final class CobrancaCadastroService extends RegistroDeCloserBase
{
    private const LIMITE_LISTA = 200;

    public function __construct(VendedorRepository $vendedores, private CobrancaCadastroRepository $cobrancas)
    {
        parent::__construct($vendedores);
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $equipeId): array
    {
        $linhas = array_slice($this->cobrancas->listar($equipeId), 0, self::LIMITE_LISTA);
        $nomes = $this->nomesDosCloseres($linhas);
        return array_map(fn(array $r): array => $this->item($r, $nomes), $linhas);
    }

    /** @param mixed $closerId @param mixed $observacao @param mixed $data */
    public function criar(int $equipeId, int $liderVendedorId, $closerId, $observacao, $data): Resultado
    {
        $erros = [];
        $erroCloser = $this->erroDoCloser($equipeId, $liderVendedorId, $closerId);
        if ($erroCloser !== null) {
            $erros['closer_id'] = $erroCloser;
        }
        $obs = RegistroValidador::texto($observacao ?? '', 255);
        if ($obs === null) {
            $erros['observacao'] = 'A observação pode ter até 255 caracteres.';
        }
        $d = $this->dataDoRegistro($data);
        if (is_string($d)) {
            $erros['data'] = $d;
        }
        if ($erros) {
            return Resultado::falha(reset($erros), $erros);
        }
        $id = $this->cobrancas->inserir($equipeId, (int) $closerId, $liderVendedorId, $d[0], $obs === '' ? null : $obs, $this->agora());
        return Resultado::ok(['item' => $this->itemPorId($id, $equipeId)], 'Cobrança registrada.');
    }

    /** Só a observação muda. @param mixed $observacao */
    public function editar(int $equipeId, int $id, $observacao): Resultado
    {
        if ($this->cobrancas->buscar($id, $equipeId) === null) {
            return Resultado::falha('Cobrança não encontrada.');
        }
        $obs = RegistroValidador::texto($observacao ?? '', 255);
        if ($obs === null) {
            return Resultado::falha('A observação pode ter até 255 caracteres.', ['observacao' => 'A observação pode ter até 255 caracteres.']);
        }
        $this->cobrancas->atualizar($id, $equipeId, $obs === '' ? null : $obs, $this->agora());
        return Resultado::ok(['item' => $this->itemPorId($id, $equipeId)], 'Cobrança atualizada.');
    }

    /** @return array<string, mixed> */
    private function itemPorId(int $id, int $equipeId): array
    {
        $r = $this->cobrancas->buscar($id, $equipeId) ?? [];
        return $r === [] ? [] : $this->item($r, $this->nomesDosCloseres([$r]));
    }

    /** @param array<string, mixed> $r @param array<int, string> $nomes @return array<string, mixed> */
    private function item(array $r, array $nomes): array
    {
        return [
            'id' => (int) $r['id'],
            'closer_id' => (int) $r['closer_vendedor_id'],
            'closer_nome' => $nomes[(int) $r['closer_vendedor_id']] ?? '',
            'data' => (string) $r['data'],
            'observacao' => (string) ($r['observacao'] ?? ''),
            'criado_em' => (string) $r['criado_em'],
            'atualizado_em' => isset($r['atualizado_em']) ? (string) $r['atualizado_em'] : null,
            'visto' => isset($r['visto_em']) && $r['visto_em'] !== null,
        ];
    }
}

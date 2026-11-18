<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Resultado;
use App\Repositories\RevisaoAtendimentoRepository;
use App\Repositories\VendedorRepository;

/**
 * Revisões de atendimento: o líder revisa o atendimento de um closer da equipe a um cliente, identificado pelo telefone do cliente
 * (validado com as regras do portal e guardado só com dígitos). Criar e editar telefone e observação; não há exclusão.
 */
final class RevisaoAtendimentoService extends RegistroDeCloserBase
{
    private const LIMITE_LISTA = 200;

    public function __construct(VendedorRepository $vendedores, private RevisaoAtendimentoRepository $revisoes)
    {
        parent::__construct($vendedores);
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $equipeId): array
    {
        $linhas = array_slice($this->revisoes->listar($equipeId), 0, self::LIMITE_LISTA);
        $nomes = $this->nomesDosCloseres($linhas);
        return array_map(fn(array $r): array => $this->item($r, $nomes), $linhas);
    }

    /** @param mixed $closerId @param mixed $telefone @param mixed $observacao @param mixed $data */
    public function criar(int $equipeId, int $liderVendedorId, $closerId, $telefone, $observacao, $data): Resultado
    {
        $erros = [];
        $erroCloser = $this->erroDoCloser($equipeId, $liderVendedorId, $closerId);
        if ($erroCloser !== null) {
            $erros['closer_id'] = $erroCloser;
        }
        $fone = Telefone::digitos($telefone);
        if (!Telefone::valido($fone)) {
            $erros['telefone'] = 'Telefone inválido. Use DDD + número, ex.: (11) 98765-4321.';
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
        $id = $this->revisoes->inserir($equipeId, (int) $closerId, $fone, $obs === '' ? null : $obs, $liderVendedorId, $d[0], $this->agora());
        return Resultado::ok(['item' => $this->itemPorId($id, $equipeId)], 'Revisão registrada.');
    }

    /** O closer e a data ficam; telefone e observação mudam. @param mixed $telefone @param mixed $observacao */
    public function editar(int $equipeId, int $id, $telefone, $observacao): Resultado
    {
        if ($this->revisoes->buscar($id, $equipeId) === null) {
            return Resultado::falha('Revisão não encontrada.');
        }
        $erros = [];
        $fone = Telefone::digitos($telefone);
        if (!Telefone::valido($fone)) {
            $erros['telefone'] = 'Telefone inválido. Use DDD + número, ex.: (11) 98765-4321.';
        }
        $obs = RegistroValidador::texto($observacao ?? '', 255);
        if ($obs === null) {
            $erros['observacao'] = 'A observação pode ter até 255 caracteres.';
        }
        if ($erros) {
            return Resultado::falha(reset($erros), $erros);
        }
        $this->revisoes->atualizar($id, $equipeId, $fone, $obs === '' ? null : $obs, $this->agora());
        return Resultado::ok(['item' => $this->itemPorId($id, $equipeId)], 'Revisão atualizada.');
    }

    /** @return array<string, mixed> */
    private function itemPorId(int $id, int $equipeId): array
    {
        $r = $this->revisoes->buscar($id, $equipeId) ?? [];
        return $r === [] ? [] : $this->item($r, $this->nomesDosCloseres([$r]));
    }

    /** @param array<string, mixed> $r @param array<int, string> $nomes @return array<string, mixed> */
    private function item(array $r, array $nomes): array
    {
        return [
            'id' => (int) $r['id'],
            'closer_id' => (int) $r['closer_vendedor_id'],
            'closer_nome' => $nomes[(int) $r['closer_vendedor_id']] ?? '',
            'telefone' => (string) $r['telefone_cliente'],
            'telefone_fmt' => Telefone::formatar((string) $r['telefone_cliente']),
            'data' => (string) $r['data'],
            'observacao' => (string) ($r['observacao'] ?? ''),
            'criado_em' => (string) $r['criado_em'],
            'atualizado_em' => isset($r['atualizado_em']) ? (string) $r['atualizado_em'] : null,
        ];
    }
}

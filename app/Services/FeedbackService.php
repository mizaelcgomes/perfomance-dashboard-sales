<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Resultado;
use App\Repositories\FeedbackRepository;
use App\Repositories\VendedorRepository;

/** Feedbacks individuais do líder para um closer da equipe: criar e editar (assunto e texto). Não há exclusão. */
final class FeedbackService extends RegistroDeCloserBase
{
    private const LIMITE_LISTA = 200;

    public function __construct(VendedorRepository $vendedores, private FeedbackRepository $feedbacks)
    {
        parent::__construct($vendedores);
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $equipeId): array
    {
        $linhas = array_slice($this->feedbacks->listarDaEquipe($equipeId), 0, self::LIMITE_LISTA);
        $nomes = $this->nomesDosCloseres($linhas);
        return array_map(fn(array $r): array => $this->item($r, $nomes), $linhas);
    }

    /** @param mixed $closerId @param mixed $assunto @param mixed $texto @param mixed $data */
    public function criar(int $equipeId, int $liderVendedorId, $closerId, $assunto, $texto, $data): Resultado
    {
        $erros = $this->validar($assunto, $texto);
        $erroCloser = $this->erroDoCloser($equipeId, $liderVendedorId, $closerId);
        if ($erroCloser !== null) {
            $erros = ['closer_id' => $erroCloser] + $erros;
        }
        $d = $this->dataDoRegistro($data);
        if (is_string($d)) {
            $erros['data'] = $d;
        }
        if ($erros) {
            return Resultado::falha(reset($erros), $erros);
        }
        $id = $this->feedbacks->inserir($equipeId, (int) $closerId, $liderVendedorId, $d[0], (string) RegistroValidador::texto($assunto, 120), (string) RegistroValidador::texto($texto, 2000, true), $this->agora());
        return Resultado::ok(['item' => $this->itemPorId($id, $equipeId)], 'Feedback registrado.');
    }

    /** Só assunto e texto mudam; a data e o closer ficam como foram registrados. @param mixed $assunto @param mixed $texto */
    public function editar(int $equipeId, int $id, $assunto, $texto): Resultado
    {
        if ($this->feedbacks->buscar($id, $equipeId) === null) {
            return Resultado::falha('Feedback não encontrado.');
        }
        $erros = $this->validar($assunto, $texto);
        if ($erros) {
            return Resultado::falha(reset($erros), $erros);
        }
        $this->feedbacks->atualizar($id, $equipeId, (string) RegistroValidador::texto($assunto, 120), (string) RegistroValidador::texto($texto, 2000, true), $this->agora());
        return Resultado::ok(['item' => $this->itemPorId($id, $equipeId)], 'Feedback atualizado.');
    }

    /** @param mixed $assunto @param mixed $texto @return array<string, string> */
    private function validar($assunto, $texto): array
    {
        $erros = [];
        $a = RegistroValidador::texto($assunto, 120);
        if ($a === null || $a === '') {
            $erros['assunto'] = $a === null ? 'O assunto pode ter até 120 caracteres.' : 'Informe o assunto do feedback.';
        }
        $t = RegistroValidador::texto($texto, 2000, true);
        if ($t === null || $t === '') {
            $erros['texto'] = $t === null ? 'O texto pode ter até 2000 caracteres.' : 'Escreva o feedback.';
        }
        return $erros;
    }

    /** @return array<string, mixed> */
    private function itemPorId(int $id, int $equipeId): array
    {
        $r = $this->feedbacks->buscar($id, $equipeId) ?? [];
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
            'assunto' => (string) $r['assunto'],
            'texto' => (string) $r['texto'],
            'criado_em' => (string) $r['criado_em'],
            'atualizado_em' => isset($r['atualizado_em']) ? (string) $r['atualizado_em'] : null,
            'visto' => isset($r['visto_em']) && $r['visto_em'] !== null,
        ];
    }
}

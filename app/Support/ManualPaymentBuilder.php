<?php

namespace App\Support;

use App\Models\scctbill;
use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ManualPaymentBuilder
{
    private const SALDO_FIDBANK = '1140002';

    public function payBill(
        scctbill $tagihan,
        int $nominal,
        string $fidBank,
        string $paidAt,
        ?string $transno,
        Request $request
    ): void {
        $custId = (string) $tagihan->CUSTID;
        $aa = (string) $tagihan->AA;
        $billCd = (string) ($tagihan->BILLCD ?? '');
        $userId = $this->resolveCyberKeyUserId();
        $paymentDateYmd = Carbon::parse($paidAt)->format('Ymd');

        if ($fidBank === self::SALDO_FIDBANK) {
            $this->callBuilderPaymentBill($aa, $nominal);
            return;
        }

        $this->callBuilderPaymentCash(
            $custId,
            $fidBank,
            $userId,
            $paymentDateYmd,
            $billCd,
            $aa,
            $nominal
        );
    }

    /**
     * BuilderPaymentCash(v_CUSTID, p_FIDBANK, p_User, p_Date, p_BILLCD, p_AA, p_Payment)
     * p_Date format: YYYYMMDD
     */
    private function callBuilderPaymentCash(
        string $custId,
        string $fidBank,
        string $userId,
        string $paymentDateYmd,
        string $billCd,
        string $aa,
        int $nominal
    ): void {
        Log::info('manual-payment.builder.call', [
            'function' => 'BuilderPaymentCash',
            'custid' => $custId,
            'fidbank' => $fidBank,
            'user' => $userId,
            'date' => $paymentDateYmd,
            'billcd' => $billCd,
            'aa' => $aa,
            'payment' => $nominal,
        ]);

        $result = $this->invokeStoredFunction('BuilderPaymentCash', [
            $custId,
            $fidBank,
            $userId,
            $paymentDateYmd,
            $billCd,
            $aa,
            $nominal,
        ]);

        $this->assertOkResult('BuilderPaymentCash', $result);
    }

    /** BuilderPaymentBill — 2 param (sesuaikan jika definition DB berbeda) */
    private function callBuilderPaymentBill(string $aa, int $nominal): void
    {
        Log::info('manual-payment.builder.call', [
            'function' => 'BuilderPaymentBill',
            'aa' => $aa,
            'nominal' => $nominal,
        ]);

        $result = $this->invokeStoredFunction('BuilderPaymentBill', [
            $aa,
            $nominal,
        ]);

        $this->assertOkResult('BuilderPaymentBill', $result);
    }

    private function invokeStoredFunction(string $functionName, array $params): ?string
    {
        $placeholders = implode(', ', array_fill(0, count($params), '?'));

        $rows = DB::connection('DATA_MYSQL')->select(
            "SELECT {$functionName}({$placeholders}) AS result",
            $params
        );

        return isset($rows[0]) ? (string) ($rows[0]->result ?? '') : null;
    }

    private function assertOkResult(string $functionName, ?string $result): void
    {
        if ($result === 'OK') {
            return;
        }

        Log::warning('manual-payment.builder.rejected', [
            'function' => $functionName,
            'result' => $result,
        ]);

        throw new RuntimeException($this->translateBuilderResult($result));
    }

    private function translateBuilderResult(?string $result): string
    {
        return match ($result) {
            'NOMINAL_SALAH_TAGIHAN_TIDAK_BOLEH_DICICIL' => 'Tagihan tidak boleh dicicil. Nominal harus sama dengan sisa tagihan.',
            'MELEBIHI_TAGIHAN' => 'Nominal pembayaran melebihi sisa tagihan.',
            null, '' => 'Function pembayaran tidak mengembalikan hasil.',
            default => 'Pembayaran ditolak function DB: ' . $result,
        };
    }

    private function resolveCyberKeyUserId(): string
    {
        $user = Auth::user();

        if ($user === null) {
            return '';
        }

        return (string) ($user->urut ?? Auth::id() ?? '');
    }
}

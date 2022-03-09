<?php

namespace App\Http\Controllers;

use App\Models\MpesaAttempt;
use App\Models\ServiceRecords;
use App\Models\User;
use App\Models\WalletStatements;
use App\Models\WalletTransactions;
use App\Mpesa\Mpesa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class TransactionController extends Controller
{

    public function stkPush($amount, $phone, $userId, $uname)
    {
        Log::info('---->Initiating STK Push with phone number -->' . $phone);
        $req = Mpesa::STKPushSimulation($amount, $phone, $userId, $uname);
        return json_decode($req, TRUE);
    }

    public function deposit(Request $request)
    {
        try {

            $last_trx = DB::table('mpesa_attempts')->latest()->first();
            if ($last_trx == null) {
                $trx_id = 1;
            } else {
                $trx_id = (int)$last_trx->id + 1;
            }

            $responseJson = $this->stkpush($request->amount, $request->phone, $request->userId, $request->userId);

            if (array_key_exists('fault', $responseJson)) {
                return response()->json(['error' => $responseJson['fault']], 409);
            }

            $mpesaAttempt = new MpesaAttempt(array(
                'MerchantRequestID' => $responseJson['MerchantRequestID'],
                'CheckoutRequestID' => $responseJson['CheckoutRequestID'],
                'trx_id' => $trx_id,
                'ResultCode' => $responseJson['ResponseCode'],
                'ResultDesc' => $responseJson['ResponseDescription'],
                'amount' => $request->amount,
                'userId' => $request->userId,
                'status' => 'Awaiting Response',
                'trx_description' => 'Transaction Initiated. Awaiting Response'
            ));
            $mpesaAttempt->save();
            Log::info('---->Mpesa payment initiated with checkout Request id ' . $responseJson['CheckoutRequestID']);

            return $responseJson;

        } catch (\Exception $exception) {
            Log::info('---->Mpesa payment failed due to ' . $exception->getMessage());

            return $exception->getMessage();
        }

    }

    public function callback(Request $request)
    {
        Log::info('---->Mpesa Callback Data ' . $request);
        $Amount = null;
        $content = json_decode($request->createFromGlobals()->getContent(), true);

        Log::info('---->Mpesa Callback Data ' . $content['Body']['stkCallback']['CheckoutRequestID']);

        if ($content['Body']['stkCallback']['ResultCode'] == 1032) {
            MpesaAttempt::where('CheckoutRequestID', $content['Body']['stkCallback']['CheckoutRequestID'])->update(['status' => 'Failed']);

        } else if ($content['Body']['stkCallback']['ResultCode'] == 0) {

            try {
                $CheckoutRequestID = $content['Body']['stkCallback']['CheckoutRequestID'];
                MpesaAttempt::where('CheckoutRequestID', $CheckoutRequestID)->update(['status' => 'Successful']);
                $mpesaAttempt = MpesaAttempt::where('CheckoutRequestID', $CheckoutRequestID)->first();
                for ($i = 0; $i < count($content['Body']['stkCallback']['CallbackMetadata']['Item']); $i++) {
                    switch ($content['Body']['stkCallback']['CallbackMetadata']['Item'][$i]['Name']) {
                        case 'Amount':
                            $Amount = $content['Body']['stkCallback']['CallbackMetadata']['Item'][$i]['Value'];
                            break;
                        case 'MpesaReceiptNumber':
                            $transID = $content['Body']['stkCallback']['CallbackMetadata']['Item'][$i]['Value'];
                            break;
                        case 'PhoneNumber':
                            $phone = $content['Body']['stkCallback']['CallbackMetadata']['Item'][$i]['Value'];
                            break;
                        case 'TransactionDate':
                            $transDate = $content['Body']['stkCallback']['CallbackMetadata']['Item'][$i]['Value'];
                            break;
                        default:
                            break;
                    }
                }
                /**
                 * Begin transaction
                 */
                $ref = MpesaAttempt::where('CheckoutRequestID', $CheckoutRequestID)->first();
                DB::beginTransaction();

                try {
                    $walletStatements = WalletStatements::where('userId', $mpesaAttempt->userId)->orderBy("created_at", "desc")->first();
                    $mpesaPayment = new WalletTransactions(array(
                        'userId' => $mpesaAttempt->userId,
                        'PhoneNumber' => $phone,
                        'TransactionId' => $transID,
                        'TransactionDesc' => 'Mpesa Deposit',
                        'TransactionType' => 'Debit',
                        'Description' => "STK PUSH",
                        'ExtraData' => $request->userId,
                        'Amount' => $Amount
                    ));

                    if ($mpesaPayment->save()) {
                        Log::info('---->Mpesa payment callback ready to be saved' . $mpesaPayment);

                        if ($walletStatements == null) {
                            Log::info('---->Could not find Record with User Id ' . $mpesaAttempt->userId);
                            $walletStatement = new WalletStatements(array(
                                'userId' => $mpesaAttempt->userId,
                                'ClosingBalance' => $Amount,
                                'TotalDebit' => $Amount,
                                'TotalCredit' => 0,
                            ));
                            $walletStatement->save();

                            $data = ['currentBalance' => $Amount];
                        } else {

                            $closingBalance = $walletStatements->ClosingBalance + $Amount;
                            $totalDebit = $walletStatements->TotalDebit + $Amount;

                            WalletStatements::where('userId', $mpesaAttempt->userId)->update(['ClosingBalance' => $closingBalance, 'TotalDebit' => $totalDebit]);

                            Log::info('---->Successfully Updated Wallet Statement for ' . $mpesaAttempt->userId . 'with values' . $closingBalance . 'and' . $totalDebit);

                            $data = ['currentBalance' => $closingBalance];
                        }
                    }
                    DB::commit();

                    return response()->json(['message' => 'Success', 'data' => $data,], 202);

                } catch (\Exception $exception) {

                    DB::rollBack();
                    Log::error('---->Transaction Rollback, Could not save Mpesa Callback Data ' . $exception->getMessage());
                    return response()->json(['message' => 'Error', 'data' => $exception->getMessage(),], 500);
                }

            } catch (\Exception $exception) {
                Log::error('---->An Error Occurred due to : ' . $exception->getMessage());

            }

        }
    }

    public function checkPaymentStatus(Request $request)
    {
        $CheckoutRequestID = $request->input('CheckoutRequestID');
        $mpesaAttempt = MpesaAttempt::where('CheckoutRequestID', $CheckoutRequestID)->first();
        return response()->json(['TransactionStatus' => $mpesaAttempt->status, 'userId' => $mpesaAttempt->userId]);
    }

    /**
     * Register validation and confirmation url
     */
    public function registerUrl()
    {
        Log::info('---->Register Urls');
        $req = Mpesa::registerUrl();
        return json_decode($req, TRUE);
    }

    public function validateTransaction(Request $request)
    {
        /*
         * BillReference validation
         * Check whether the BillRef Number is the user id of an existing user
         */

        Log::info("----->Validation request received ", request()->all());

        $userId = User::where('userName', $request->BillRefNumber)->first();

        if ($userId == null) {
            //Reject transaction
            $reqBody = array("ResultCode" => 1, "ResultDesc" => "Rejected");
        } else {
            //Accept transaction
            $reqBody = array("ResultCode" => 0, "ResultDesc" => "Accepted");
        }
        return response(json_encode($reqBody))->header('Content-Type', 'Application/Json');
    }

    public function confirmTransaction(Request $request)
    {
        Log::info('---->Mpesa C2B confirmation response has been received successfully');

        try {
            DB::beginTransaction();
            $walletStatements = WalletStatements::where('userId', $request->BillRefNumber)->orderBy("created_at", "desc")->first();
            $mpesaPayment = new WalletTransactions(array(
                'userId' => $request->BillRefNumber,
                'PhoneNumber' => $request->MSISDN,
                'TransactionId' => $request->TransID,
                'TransactionDesc' => 'Mpesa Deposit',
                'TransactionType' => 'Debit',
                'Description' => "C2B DEPOSIT",
                'ExtraData' => $request->BillRefNumber,
                'Amount' => $request->TransAmount
            ));


            if ($mpesaPayment->save()) {
                Log::info('---->Mpesa C2B confirmation response ready to be saved' . $mpesaPayment);

                if ($walletStatements == null) {
                    Log::info('---->Could not find Record with User Id ' . $request->BillRefNumber);
                    //TODO: WalletStatements below is my class implementation

                    $walletStatement = new WalletStatements(array(
                        'userId' => $request->BillRefNumber,
                        'ClosingBalance' => $request->TransAmount,
                        'TotalDebit' => $request->TransAmount,
                        'TotalCredit' => 0,
                    ));
                    $walletStatement->save();

                    $data = ['currentBalance' => $request->TransAmount];
                } else {

                    $closingBalance = $walletStatements->ClosingBalance + $request->TransAmount;
                    $totalDebit = $walletStatements->TotalDebit + $request->TransAmount;

                    WalletStatements::where('userId', $request->BillRefNumber)->update(['ClosingBalance' => $closingBalance, 'TotalDebit' => $totalDebit]);

                    Log::info('---->Successfully Updated Wallet Statement for ' . $request->BillRefNumber . 'with values' . $closingBalance . 'and' . $totalDebit);

                    $data = ['currentBalance' => $closingBalance];
                }
            }
            DB::commit();

            return response()->json(['message' => 'Success', 'data' => $data,], 202);

        } catch (\Exception $exception) {
            DB::rollBack();

            Log::error('---->Transaction Rollback, Could not save Mpesa Callback Data ' . $exception->getMessage());
            return response()->json(['message' => 'Error', 'data' => $exception->getMessage(),], 500);
        }

    }

}

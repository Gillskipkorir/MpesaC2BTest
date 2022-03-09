<?php

namespace App\Mpesa;


class Mpesa
{
    /**
     * This is used to generate tokens for the live environment
     * @return mixed
     */
    public function getCurrentRequestTime()
    {
        date_default_timezone_set('UTC');
        $date = new \DateTime();
        return $date->format('YmdHis');
    }

    public function getPassword($shortcode, $passkey, $timestamp)
    {
        return base64_encode($shortcode . $passkey . $timestamp);
    }

    public static function generateLiveToken()
    {

        try {
            $consumer_key = env('MPESA_CONSUMER_KEY');
            $consumer_secret = env('MPESA_CONSUMER_SECRET');
        } catch (\Throwable $th) {
            $consumer_key = env('MPESA_CONSUMER_KEY');
            $consumer_secret = env('MPESA_CONSUMER_SECRET');
        }
        if (!isset($consumer_key) || !isset($consumer_secret)) {
            die("please declare the consumer key and consumer secret as defined in the documentation");
        }
        $url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        $credentials = base64_encode($consumer_key . ':' . $consumer_secret);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . $credentials)); //setting a custom header
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $curl_response = curl_exec($curl);
        return json_decode($curl_response)->access_token;
    }


    public static function generateSandBoxToken()
    {

        try {
            $consumer_key = env('MPESA_CONSUMER_KEY');
            $consumer_secret = env('MPESA_CONSUMER_SECRET');
        } catch (\Throwable $th) {
            $consumer_key = env('MPESA_CONSUMER_KEY');
            $consumer_secret = env('MPESA_CONSUMER_SECRET');
        }
        if (!isset($consumer_key) || !isset($consumer_secret)) {
            die("please declare the consumer key and consumer secret as defined in the documentation");
        }
        $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        $credentials = base64_encode($consumer_key . ':' . $consumer_secret);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . $credentials)); //setting a custom header
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $curl_response = curl_exec($curl);
        return json_decode($curl_response)->access_token;
    }


    public static function c2b($Amount, $BillRefNumber, $Msisdn)
    {

        try {
            $environment = env("MPESA_ENV");
        } catch (\Throwable $th) {
            $environment = env("MPESA_ENV");
        }

        if ($environment == "live") {
            $url = 'https://api.safaricom.co.ke/mpesa/c2b/v1/simulate';
            $token = self::generateLiveToken();
        } elseif ($environment == "sandbox") {
            $url = 'https://sandbox.safaricom.co.ke/mpesa/c2b/v1/simulate';
            $token = self::generateSandBoxToken();
        } else {
            return json_encode(["Message" => "invalid application status"]);
        }
        self::registerUrl();
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $token));
        $curl_post_data = array(
            'ShortCode' => env("SHORTCODE"),
            'CommandID' => env("MPESA_C2B_COMMAND_ID"),
            'Amount' => $Amount,
            'Msisdn' => $Msisdn,
            'BillRefNumber' => $BillRefNumber
        );
        $data_string = json_encode($curl_post_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $curl_response = curl_exec($curl);
        echo $curl_response;
    }



    /**
     * Register validation and confirmation url
     */
    public static function registerUrl()
    {
        try {
            $environment = env('MPESA_ENV');
        } catch (\Throwable $th) {
            $environment = env('MPESA_ENV');
        }
        if ($environment == "live") {
            $url = 'https://api.safaricom.co.ke/mpesa/c2b/v1/registerurl';
            $token = self::generateLiveToken();
        } elseif ($environment == "sandbox") {
            $url = 'https://sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl';
            $token = self::generateSandBoxToken();
        } else {
            return json_encode(["Message" => "invalid application status"]);
        }
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $token));

        $curl_post_data = array(
            //Fill in the request parameters with valid values
            'ShortCode' => env('MPESA_SHORTCODE'),
            'ResponseType' => 'Completed',
            'ValidationURL' => env('MPESA_VALIDATION_URL'),
            'ConfirmationURL' => env('MPESA_CONFIRMATION_URL'),
        );

        $data_string = json_encode($curl_post_data);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);

        $curl_response = curl_exec($curl);
        print_r($curl_response);

    }

    /**
     *Use this function to confirm all transactions in callback routes
     */
    public function finishTransaction($status = true)
    {
        if ($status === true) {
            $resultArray = [
                "ResultDesc" => "Confirmation Service request accepted successfully",
                "ResultCode" => "0"
            ];
        } else {
            $resultArray = [
                "ResultDesc" => "Confirmation Service not accepted",
                "ResultCode" => "1"
            ];
        }
        header('Content-Type: application/json');
        echo json_encode($resultArray);
    }

    /**
     *Use this function to get callback data posted in callback routes
     */
    public function getDataFromCallback()
    {
        $callbackJSONData = file_get_contents('php://input');
        return $callbackJSONData;
    }

}

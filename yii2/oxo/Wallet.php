<?php
namespace app\components\payme\oxo;

use app\models\user\User;
use shoxabbos\paymeuz\AbstractPayme;
use shoxabbos\paymeuz\PaymeResponse;
use shoxabbos\paymeuz\yii2\DbTransactionProvider;

class Wallet extends AbstractPayme
{
    /**
     * List fields
     *
     * @var array $accounts
     */
    protected $accounts = ["id"];


    /**
     * Table of transactions
     *
     * @var string $tableName
     */
    protected $tableName = "payme_uz";


    /**
     * Min summ
     *
     * @var int $minSum
     */
    protected $minSum = 1000;


    /**
     * Max summ
     *
     * @var int $maxSum
     */
    protected $maxSum = 100000;


    /**
     * Transaction timeout
     *
     * @var int $timeout
     */
    protected $timeout = 600 * 1000;


    /**
     * @var bool $canCancelSuccessTransaction
     */
    protected $canCancelSuccessTransaction = false;

    /**
     * User primaryKey
     *
     * @var string $userKey
     */
    protected $userKey = "id";


    /**
     * Wallet constructor.
     * @param string $request JSON request
     */
    public function __construct($request)
    {
        parent::__construct($request, new DbTransactionProvider($this->tableName));
    }


    /**
     * Transaksiya otkazib bolish imkoniyatini tekshiradi
     *
     * @return array
     */
    protected function checkPerformTransaction()
    {
        // Check account fields
        if (!$this->request->hasAccounts($this->accounts) || !$this->request->hasParam(["amount"])) {
            return $this->response->error(PaymeResponse::JSON_RPC_ERROR);
        }

        // Get vars
        $accounts = $this->request->getParam('account');
        $amount = $this->request->getParam("amount");

        // Check user
        if (!User::findOne($accounts[$this->userKey])) {
            return $this->response->error(PaymeResponse::USER_NOT_FOUND);
        }

        // Check amount
        if ($amount < $this->minSum || $amount > $this->maxSum) {
            return $this->response->error(PaymeResponse::WRONG_AMOUNT);
        }

        // Success
        return $this->response->successCheckPerformTransaction();
    }


    /**
     * Transaksiya yaratadi
     *
     * @return array
     */
    protected function createTransaction()
    {
        // Check account fields
        if (!$this->request->hasAccounts($this->accounts) || !$this->request->hasParam(["amount", "time", "id"])) {
            return $this->response->error(PaymeResponse::JSON_RPC_ERROR);
        }

        $accounts = $this->request->getParam('account');
        $amount = $this->request->getParam("amount");
        $transId = $this->request->getParam("id");
        $time = $this->request->getParam("time");

        // Check amount
        if ($amount < $this->minSum || $amount > $this->maxSum) {
            return $this->response->error(PaymeResponse::WRONG_AMOUNT);
        }

        // Check user
        if (!User::findOne($accounts[$this->userKey])) {
            return $this->response->error(PaymeResponse::USER_NOT_FOUND);
        }


        if ($trans = $this->provider->getByTransId($transId)) {
            if ($trans['state'] != 1) {
                return $this->response->error(PaymeResponse::CANT_PERFORM_TRANS);
            }

            return $this->response->successCreateTransaction($trans['create_time'], $trans['id'], $trans['state']);
        }


        // Add new transaction
        try {
            $this->provider->insert([
                'transaction' => $transId,
                'time' => $time,
                'amount' => $amount,
                'state' => 1,
                'create_time' => $this->microtime(),
                'owner_id' => $accounts[$this->userKey],
            ]);

            $trans = $this->provider->getByTransId($transId);

            return $this->response->successCreateTransaction($trans['create_time'], $trans['id'], $trans['state']);

        } catch (\Exception $e) {
            return $this->response->error(PaymeResponse::SYSTEM_ERROR);
        }
    }


    /**
     * Transaksiyani utqazish va foydalanuvchi hisobiga pul otqazish
     *
     * @return array
     */
    protected function performTransaction()
    {
        // Check fields
        if (!$this->request->hasParam(["id"])) {
            return $this->response->error(PaymeResponse::JSON_RPC_ERROR);
        }

        // Search by id
        $transId = $this->request->getParam('id');
        $trans = $this->provider->getByTransId($transId);

        if (!$trans) {
            return $this->response->error(PaymeResponse::TRANS_NOT_FOUND);
        }

        if ($trans['state'] != 1) {
            if ($trans['state'] == 2) {
                return $this->response->successPerformTransaction($trans['state'], $trans['perform_time'], $trans['id']);
            } else {
                return $this->response->error(PaymeResponse::CANT_PERFORM_TRANS);
            }
        }

        // Check timeout
        if (!$this->checkTimeout($trans['create_time'])) {
            $this->provider->update($transId, [
                "state" => -1,
                "reason" => 4
            ]);

            return $this->response->error(PaymeResponse::CANT_PERFORM_TRANS, [
                "uz" => "Vaqt tugashi o'tdi",
                "ru" => "Тайм-аут прошел",
                "en" => "Timeout passed"
            ]);
        }

        try {
            $this->fillUpUserBalance($trans['owner_id'], $trans['amount']);
            $performTime = $this->microtime();
            $this->provider->update($transId, [
                "state" => 2,
                "perform_time" => $performTime
            ]);

            return $this->response->successPerformTransaction(2, $performTime, $trans['id']);
        } catch (\Exception $e) {
            return $this->response->error(PaymeResponse::CANT_PERFORM_TRANS);
        }
    }


    /**
     * Transaksiyani statusini tekshiradi
     *
     * @return array
     */
    protected function checkTransaction()
    {
        // Check fields
        if (!$this->request->hasParam(["id"])) {
            return $this->response->error(PaymeResponse::JSON_RPC_ERROR);
        }

        $transId = $this->request->getParam("id");
        $trans = $this->provider->getByTransId($transId);

        if ($trans) {
            return $this->response->successCheckTransaction(
                $trans['create_time'],
                $trans['perform_time'],
                $trans['cancel_time'],
                $trans['id'],
                $trans['state'],
                $trans['reason']
            );
        } else {
            return $this->response->error(PaymeResponse::TRANS_NOT_FOUND);
        }
    }


    /**
     * Transaksiyani qaytarish va foydalanuvchi hisobidan yechib olish
     *
     * @return array
     */
    protected function cancelTransaction()
    {
        // Check fields
        if (!$this->request->hasParam(["id", "reason"])) {
            return $this->response->error(PaymeResponse::JSON_RPC_ERROR);
        }

        $transId = $this->request->getParam("id");
        $reason = $this->request->getParam("reason");
        $trans = $this->provider->getByTransId($transId);

        if (!$trans) {
            $this->response->error(PaymeResponse::TRANS_NOT_FOUND);
        }

        if ($trans['state'] == 1) {
            $cancelTime = $this->microtime();
            $this->provider->update($transId, [
                "state" => -1,
                "cancel_time" => $cancelTime,
                "reason" => $reason
            ]);

            return $this->response->successCancelTransaction(-1, $cancelTime, $trans['id']);
        }


        if ($trans['state'] != 2) {
            return $this->response->successCancelTransaction($trans['state'], $trans['cancel_time'], $trans['id']);
        }

        try {
            $this->withdrawUserBalance($trans['owner_id'], $trans['amount']);

            $cancelTime = $this->microtime();
            $this->provider->update($transId, [
                "state" => -2,
                "cancel_time" => $cancelTime,
                "reason" => $reason
            ]);

            return $this->response->successCancelTransaction(-2, $cancelTime, $trans['id']);
        } catch (\Exception $e) {
            return $this->response->error(PaymeResponse::CANT_CANCEL_TRANSACTION);
        }
    }


    /**
     * Hozircha bu metod hech narsa qilmaydi, lekin keyin albatta qilaman
     */
    public function getStatement()
    {
        // TODO: Implement GetStatement() method.
    }

    /**
     * Bu metod parolni uzgartirish uchun kk
     */
    protected function changePassword()
    {
        // TODO: Implement ChangePassword() method.
    }


    /**
     * Foydalanuvchi hisobiga pul otqazish
     *
     * @param $owner_id
     * @param $amount
     * @throws \Exception
     */
    private function fillUpUserBalance($owner_id, $amount)
    {
        $user = User::findOne($owner_id);
        if ($user) {
            $user->scenario = "update_balance";
            $user->balance += $amount;
            $user->save();
        } else {
            throw new \Exception("Can't fill up balance");
        }
    }

    /**
     * Foydalanuvchi hisobidan pul yechish
     *
     * @param $owner_id
     * @param $amount
     * @throws \Exception
     */
    private function withdrawUserBalance($owner_id, $amount)
    {
        $user = User::findOne($owner_id);
        if ($user && $user->balance >= $amount) {
            $user->scenario = "update_balance";
            $user->balance -= $amount;
            $user->save();
        } else {
            throw new \Exception("Can't withdraw balance");
        }
    }

    /**
     * Transaksiyani tekshiradi timeoutga qarab
     *
     * @param $created_time
     * @return bool
     */
    private function checkTimeout($created_time)
    {
        return $this->microtime() <= ($created_time + $this->timeout);
    }

}
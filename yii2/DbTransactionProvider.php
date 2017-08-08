<?php
namespace shoxabbos\paymeuz\yii2;

use shoxabbos\paymeuz\TransactionProvider;

/**
 * Bu class yii2 frameworkdan zavisit qiladi
 * To est faqat yii2 uchun
 *
 * Class DbTransactionProvider
 * @package app\components\payme
 */
class DbTransactionProvider implements TransactionProvider
{
    /**
     * Yii2 query builder
     *
     * @var \yii\db\Query
     */
    public $db;


    /**
     * Bazadagi payme transaksiyalari turadigan tablitsa
     *
     * @var string $tableName
     */
    public $tableName;



    /**
     * Query builder yaratadi va tablitsa nomini qabul qiladi
     *
     * DbTransactionProvider constructor.
     * @param $tableName
     */
    public function __construct($tableName)
    {
        $this->tableName = $tableName;
        $this->db = new \yii\db\Query();
    }


    /**
     * Bazada tranzasiya topadi $transId buyicha
     *
     * @return array|bool
     * @param $transId
     */
    public function getByTransId($transId)
    {
        $trans = $this->db->select("*")->from($this->tableName)->where(['transaction' => $transId])->one();

        if ($trans) {
            /**
             * Karoche kakoyta gluk v PDO
             * u vsex polyax tip znacheniya string
             * Hotya v db vse ok
             */
            $trans['create_time'] = intval($trans['create_time']);
            $trans['cancel_time'] = intval($trans['cancel_time']);
            $trans['perform_time'] = intval($trans['perform_time']);
            $trans['time'] = intval($trans['time']);
            $trans['state'] = is_null($trans['state']) ? null : intval($trans['state']);
            $trans['amount'] = is_null($trans['amount']) ? null : intval($trans['amount']);
            $trans['reason'] = is_null($trans['reason']) ? null : intval($trans['reason']);
        }

        return $trans;
    }


    /**
     * Transaksiya obnavit qilishi uchun
     *
     * @param $transId
     * @param array $fields
     * @return int
     */
    public function update($transId, array $fields)
    {
        return $this->db->createCommand()
            ->update($this->tableName, $fields, ['transaction' => $transId])
            ->execute();
    }


    /**
     * Yangi transaksiya qushadi
     *
     * @param array $fields
     * @return int
     */
    public function insert(array $fields)
    {
        return $this->db->createCommand()
            ->insert($this->tableName, $fields)
            ->execute();
    }

}
<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2016/12/7
 * Time: 17:11
 */
namespace api\modules\v8\controllers;

use api\modules\v8\models\Order;
use api\modules\v9\models\MemberSort;
use api\modules\v5\models\User;
use common\components\SaveToLog;
use Pingpp\Charge;
use Pingpp\Error\Base;
use Pingpp\Util\Util;
use Yii;
use Pingpp\Pingpp;
use yii\data\ActiveDataProvider;
use yii\db\Query;
use yii\myhelper\Response;
use yii\rest\ActiveController;

class OrderController extends ActiveController
{

    public $modelClass = 'api\modules\v8\models\Order';
    public $serializer = [
        'class' => 'yii\rest\Serializer',
        'collectionEnvelope' => 'items',
    ];
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        return $behaviors;
    }

    public function actions()
    {

        $actions = parent::actions();
        // 注销系统自带的实现方法
        unset($actions['index'], $actions['update'], $actions['create'], $actions['delete'], $actions['view']);
        return $actions;
    }

    public function actionView($id){


        $model = $this->modelClass;

        $detail = $model::find()->where(['user_id'=>$id,'status'=>1])->orderBy(' created_at desc ');

        return new ActiveDataProvider([
            'query' =>  $detail,
        ]);
    }

    public function actionCreate(){

        $model = new Order();
        $model->load(Yii::$app->getRequest()->getBodyParams(),'');
        $model->channel = strtolower($model->channel);
        $model->order_number = date('YmdH',time()).time();

        //监听支付状态
        if($this->getSignature()){

            $this->ListenWebhooks();exit();
        }
        //创建支付凭证
        $charge = $this->createCharge($model);
        if($charge){
            $str = array(
                'code'  => "200",
                'msg'   =>  '操作成功',
                'data'  =>  $charge,

            );
            return $str;
        }else{
            SaveToLog::log2('支付失败2','ping.log');
            http_response_code(400);
            exit();
        }

    }

    //创建支付凭证
    protected function createCharge($param){

        Pingpp::setApiKey(Yii::$app->params['api-key']);
        Pingpp::setPrivateKeyPath(Yii::getAlias('@config').'/rsa_private_key.pem');
        switch($param['type']){
            case 1:$param['subject'] = "充值节操币";$param['description'] = "充值 ".$param['total_fee'].' 节操币';break;
            case 2:
                    $param['subject'] = "会员升级";
                    if($param['sort_id'] == 4 ){
                        $param['description'] = "升级为高端";
                    }elseif($param['sort_id'] == 5){
                        $param['description'] = "升级为至尊";
                    }elseif($param['sort_id'] == 9){
                        $param['description'] = "升级为普通会员";
                    };break;
            case 3:$param['subject'] = "觅约报名";  $param['description'] = "觅约报名，花费 ".$param['total_fee'].' 操币';break;
            default:
                SaveToLog::log2('type类型错误','ping.log');
                http_response_code(400);
                break;
        }

        try{
            $ch = Charge::create(

                $arr = array(

                    'order_no'  => $param['order_number'],  //商户订单号
                    'app'       => array('id'=>Yii::$app->params['id']),    //
                    'channel'   => $param['channel'],   //支付方式$param['channel']
                    'amount'    => $param['total_fee']*100, //支付总金额$param['total_fee']
                    'client_ip' => '127.0.0.1',
                    'currency'  => 'cny',   //货币
                    'subject'   => $param['subject'],   //商品标题
                    'body'      => $param['description'],   //商品描述
                    'description' => $param['user_id'], //支付者ID
                    'metadata'  => array(
                        'sort_id'  =>  $param['sort_id'],   //高级会员编号
                        'type'=>$param['type'],     //商品编号
                    ),
                )

            );
            return $ch;

        }catch (Base $e){
            // 捕获报错信息
            if ($e->getHttpStatus() != NULL) {
                SaveToLog::log2('生成订单失败\n','ping.log');
                http_response_code(400);
                header('Status: ' . $e->getHttpStatus());
                return $e->getHttpBody();
            } else {

                return $e->getMessage();

            }
        }
    }

    //数字签名
    protected function getSignature(){

        Pingpp::setApiKey(Yii::$app->params['api-key']);
        Pingpp::setPrivateKeyPath(Yii::getAlias('@config').'/rsa_private_key.pem');
        $header = Util::getRequestHeaders();
        $signature = isset($header['X-Pingplusplus-Signature'])?$header['X-Pingplusplus-Signature']:null;
        if($signature == null ){
            return false;
        }
        return $signature;
    }

    //监听支付状态
    public function ListenWebhooks(){

        $data = file_get_contents("php://input");
        $pub_key_path = Yii::getAlias('@config').'/ping_public_key.pem';
        $signature = $this->getSignature();
        $result = $this->verify_signature($data,$signature,$pub_key_path);
        $self_data = array();
        if ($result === 1) {

            // 验证通过
        } elseif ($result === 0) {
            http_response_code(400);
            echo 'verification failed';
            exit;
        } else {
            http_response_code(400);
            echo 'verification error';
            exit;
        }
        $event = json_decode($data, true);

        $model = new Order();

        if ($event['type'] == 'charge.succeeded') {

            $charge = $event['data']['object'];
            $sorts_id = $charge['metadata']['sort_id'];
            $price2 = MemberSort::find()->where(['id'=>$sorts_id])->asArray()->one();

            //支付宝生成的订单号
            $model->alipay_order = $charge['id'];
            $model->order_number = $charge['order_no'];
            $model->total_fee = $charge['amount']/100;
            $model->description = $charge['body'];
            $model->channel = $charge['channel'];
            $model->subject = $charge['subject'];
            $model->user_id = $charge['description'];
            $model->extra = serialize($event['data']['object']);
            $model->type = $charge['metadata']['type'];

            if($model->type == 1 ){

                $price = (new Query())->from('pre_predefined_jiecao_coin')->where(['money'=>$model->total_fee])->one();

                if(!$price){
                    SaveToLog::log2('没有这个充值价格','ping.log');
                    http_response_code(400);
                    exit();
                }

                //充值节操币
                Yii::$app->db->createCommand("update pre_user_data set jiecao_coin = jiecao_coin+{$model->total_fee} where user_id={$model->user_id}")->execute();

            }elseif($model->type == 3){
                //觅约报名
            }elseif($model->type == 2){

                //会员升级
                $userInfo = User::find()->where(['id'=>$model->user_id])->asArray()->one();

                if((int)$userInfo['groupid'] == 1){

                    $self_data['realPrice'] = $price2['price_1'];
                    $self_data['realGiveaway'] = $price2['giveaway'];
                    $self_data['groupid'] = $price2['groupid'];
                    $model->total_fee=$self_data['realPrice'];

                }elseif( $userInfo['groupid'] == 2 ){

                    $price1 = MemberSort::find()->where(['groupid'=>$userInfo['groupid'],'flag'=>[1,3]])->asArray()->one();
                    $self_data['realPrice'] = $price2['price_1']-$price1['price_1'];
                    $self_data['realGiveaway'] = $price2['giveaway']-$price1['giveaway'];
                    $self_data['groupid'] = $price2['groupid'];
                    $model->total_fee=$self_data['realPrice'];

                }elseif( $userInfo['groupid'] == 3 ){

                    $price1 = MemberSort::find()->where(['groupid'=>$userInfo['groupid'],'flag'=>[1,3]])->asArray()->one();
                    if( $price2['groupid'] <= 3 ){

                        SaveToLog::log2('groupid = 3; 只能往上升级','ping.log');
                        http_response_code(400);
                        exit();
                    }else{

                        $self_data['realPrice'] = $price2['price_1']-$price1['price_1'];
                        $self_data['realGiveaway'] = $price2['giveaway']-$price1['giveaway'];
                        $self_data['groupid'] = $price2['groupid'];
                        $model->total_fee=$self_data['realPrice'];
                    }

                }else{

                    SaveToLog::log2('目前至尊已经是最高等级','ping.log');
                    http_response_code(400);
                    exit();
                }

                if (!$model->save()) {

                    SaveToLog::log2('save到数据失败','ping.log');
                    http_response_code(400);
                    exit();
                }

                $listId = $model->attributes["id"];

                if(isset($self_data["groupid"])){

                    $groupid = $self_data["groupid"];
                    $realGiveaway = $self_data["realGiveaway"];

                    Yii::$app->db->createCommand("update pre_user set groupid = {$groupid} where id={$model->user_id}")->execute();
                    Yii::$app->db->createCommand("update pre_user_data set jiecao_coin = jiecao_coin+{$realGiveaway} where user_id={$model->user_id}")->execute();
                    Yii::$app->db->createCommand("update pre_app_order_list set giveaway = {$realGiveaway} where id={$listId}")->execute();

                }
            }

            //本周末时间戳
            $week = strtotime('next sunday');
            //当月第一天
            $month = mktime(23,59,59,date('m'),date('t'),date('Y'))+1;

            Yii::$app->db->createCommand("update pre_app_order_list set week_time={$week},month_time={$month} where id={$listId}")->execute();

            header($_SERVER['SERVER_PROTOCOL'] . ' 200 OK');
            exit();
        }else{

            SaveToLog::log2('支付失败1','ping.log');
            header($_SERVER['SERVER_PROTOCOL'] . ' 500');
            exit();
        }
    }

    protected function verify_signature($raw_data, $signature, $pub_key_path) {
        $pub_key_contents = file_get_contents($pub_key_path);
        // php 5.4.8 以上，第四个参数可用常量 OPENSSL_ALGO_SHA256
        return openssl_verify($raw_data, base64_decode($signature), $pub_key_contents, 'sha256');
    }
}
# How to use?

```php
<?php

use app\components\payme\oxo\Wallet;
use yii\web\Response;

class ExampleController
{
    
    public function actionPaymeHook(){
        Yii::$app->response->format = Response::FORMAT_JSON;
        return (new Wallet(file_get_contents("php://input")))->response();
    }

} 

```
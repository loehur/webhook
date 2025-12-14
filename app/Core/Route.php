<?php

class Route extends Controller
{
    protected $method       = 'index';
    protected $param        = [];
    protected $controllerName = 'Base'; // Default controller name if no path provided
    protected $controller   = null; // Controller instance

    public function __construct()
    {
        if (isset($_GET['url'])) {
            $url = explode('/', filter_var(trim($_GET['url']), FILTER_SANITIZE_URL));
        } else {
            $url[0] = $this->controllerName;
        }

        if (file_exists('app/Controllers/' . $url[0] . '.php')) {
            $this->controllerName = $url[0];
        } else {
            require_once 'app/Controllers/Base.php';
            $this->controller = new Base();
            $this->controller->index();
            return;
        }

        require_once 'app/Controllers/' . $this->controllerName . '.php';
        $this->controller =  new $this->controllerName;

        if (isset($url[1])) {
            if (method_exists($this->controller, $url[1])) {
                $this->method = $url[1];
            }
        }

        //BUANG URL CONTROLER DAN METHOD UNTUK MENGAMBIL PARAMETER
        unset($url[0]);
        unset($url[1]);
        $this->param = $url;

        //PANGGIL CLASS
        call_user_func_array([$this->controller, $this->method], $this->param);
    }
}

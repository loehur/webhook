<?php

class Route extends Controller
{
    protected $method       = 'index';
    protected $param        = [];
    protected $controller   = 'Base'; // Default to Error controller if no path provided

    public function __construct()
    {
        if (isset($_GET['url'])) {
            $url = explode('/', filter_var(trim($_GET['url']), FILTER_SANITIZE_URL));
        } else {
            $url[0] = $this->controller;
        }

        if (file_exists('app/Controllers/' . $url[0] . '.php')) {
            $this->controller = $url[0];
        } else {
            require_once 'app/Controllers/Base.php';
            $this->controller = new Base();
            $this->controller->index(); 
            return;
        }

        require_once 'app/Controllers/' . $this->controller . '.php';
        $this->controller =  new $this->controller;

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

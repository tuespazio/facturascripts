<?php
/*
 * This file is part of FacturaSctipts
 * Copyright (C) 2013  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'base/fs_button.php';
require_once 'base/fs_db.php';
require_once 'base/fs_default_items.php';
require_once 'model/agente.php';
require_once 'model/empresa.php';
require_once 'model/fs_user.php';
require_once 'model/fs_page.php';
require_once 'model/fs_access.php';

class fs_controller
{
   protected $db;
   private $uptime;
   private $errors;
   private $messages;
   public $user;
   public $page;
   public $ppage;
   private $admin_page;
   protected $menu;
   public $template;
   public $css_file;
   public $custom_search;
   public $query;
   public $buttons;
   public $empresa;
   public $default_items;
   
   public function __construct($name='', $title='home', $folder='', $admin=FALSE, $shmenu=TRUE)
   {
      $tiempo = explode(' ', microtime());
      $this->uptime = $tiempo[1] + $tiempo[0];
      $this->admin_page = $admin;
      $this->errors = array();
      $this->messages = array();
      $this->db = new fs_db();
      
      $this->set_css_file();
      
      if( $this->db->connect() )
      {
         $this->user = new fs_user();
         $this->page = new fs_page( array('name'=>$name, 'title'=>$title, 'folder'=>$folder,
             'version'=>$this->version(), 'show_on_menu'=>$shmenu) );
         $this->ppage = FALSE;
         $this->empresa = new empresa();
         $this->default_items = new fs_default_items();
         
         $this->template = 'index';
         if( isset($_GET['logout']) )
         {
            if(FS_DEMO)
               $this->template = 'login/demo';
            else
               $this->template = 'login/default';
            
            $this->log_out();
         }
         else if( !$this->log_in() )
         {
            if(FS_DEMO)
               $this->template = 'login/demo';
            else
               $this->template = 'login/default';
         }
         else if( $this->user->have_access_to($this->page->name, $this->admin_page) )
         {
            if($name == '')
               $this->new_error_msg('¡Página no encontrada!');
            else
            {
               $this->set_default_items();
               
               $this->template = $name;
               $this->buttons = array();
               
               $this->custom_search = FALSE;
               if( isset($_POST['query']) )
                  $this->query = $_POST['query'];
               else if( isset($_GET['query']) )
                  $this->query = $_GET['query'];
               else
                  $this->query = '';
               
               $this->process();
            }
         }
         else
         {
            $this->new_error_msg("Acceso denegado.");
            $this->user->clean_cache(TRUE);
            $this->empresa->clean_cache();
         }
      }
      else
      {
         $this->template = 'no_db';
         $this->new_error_msg('¡Imposible conectar con la base de datos!');
      }
   }
   
   public function close()
   {
      $this->db->close();
   }
   
   public function new_error_msg($msg=FALSE)
   {
      if( $msg )
         $this->errors[] = $msg;
   }
   
   public function get_errors()
   {
      if( isset($this->empresa) )
         return array_merge($this->errors, $this->empresa->get_errors());
      else
         return $this->errors;
   }
   
   public function new_message($msg=FALSE)
   {
      if( $msg )
         $this->messages[] = $msg;
   }
   
   public function get_messages()
   {
      return $this->messages;
   }
   
   public function url()
   {
      return $this->page->url();
   }
   
   private function log_in()
   {
      if( isset($_POST['user']) AND isset($_POST['password']) )
      {
         if( FS_DEMO ) /// en el modo demo nos olvidamos de la contraseña
         {
            $user = $this->user->get($_POST['user']);
            if( !$user )
            {
               $user = new fs_user();
               $user->nick = $_POST['user'];
               $user->password = 'demo';
               $user->admin = TRUE;
               
               /// creamos un agente para asociarlo
               $agente = new agente();
               $agente->codagente = $agente->get_new_codigo();
               $agente->nombre = $_POST['user'];
               $agente->apellidos = 'Demo';
               if( $agente->save() )
                  $user->codagente = $agente->codagente;
            }
            
            $user->new_logkey();
            if( $user->save() )
            {
               setcookie('user', $user->nick, time()+FS_COOKIES_EXPIRE);
               setcookie('logkey', $user->log_key, time()+FS_COOKIES_EXPIRE);
               $this->user = $user;
               $this->load_menu();
            }
         }
         else
         {
            $user = $this->user->get($_POST['user']);
            $password = strtolower($_POST['password']);
            if($user)
            {
               if( $user->password == sha1($password) )
               {
                  $user->new_logkey();
                  if( $user->save() )
                  {
                     setcookie('user', $user->nick, time()+FS_COOKIES_EXPIRE);
                     setcookie('logkey', $user->log_key, time()+FS_COOKIES_EXPIRE);
                     $this->user = $user;
                     $this->load_menu();
                  }
               }
               else
                  $this->new_error_msg('¡Contraseña incorrecta!');
            }
            else
            {
               $this->new_error_msg('El usuario no existe!');
               $this->user->clean_cache(TRUE);
               $this->empresa->clean_cache();
            }
         }
      }
      else if( isset($_COOKIE['user']) AND isset($_COOKIE['logkey']) )
      {
         $user = $this->user->get($_COOKIE['user']);
         if($user)
         {
            if($user->log_key == $_COOKIE['logkey'])
            {
               $user->logged_on = TRUE;
               $user->update_login();
               $this->user = $user;
               $this->load_menu();
            }
            else
            {
               $this->new_message('¡Cookie no válida!');
               $this->log_out();
            }
         }
         else
         {
            $this->new_message('¡El usuario no existe!');
            $this->log_out();
            $this->user->clean_cache(TRUE);
            $this->empresa->clean_cache();
         }
      }
      return $this->user->logged_on;
   }
   
   private function log_out()
   {
      setcookie('logkey', '', time()-FS_COOKIES_EXPIRE);
   }
   
   public function duration()
   {
      $tiempo = explode(" ", microtime());
      return (number_format($tiempo[1] + $tiempo[0] - $this->uptime, 3) . ' s');
   }
   
   public function selects()
   {
      return $this->db->get_selects();
   }
   
   public function transactions()
   {
      return $this->db->get_transactions();
   }
   
   public function get_db_history()
   {
      return $this->db->get_history();
   }
   
   protected function load_menu($reload=FALSE)
   {
      $this->menu = $this->user->get_menu($reload);
      
      /// actualizamos los datos de la página
      foreach($this->menu as $m)
      {
         if($m->name == $this->page->name AND $m != $this->page)
         {
            $this->page->save();
            break;
         }
      }
   }
   
   public function folders()
   {
      $folders = array();
      foreach($this->menu as $m)
      {
         if($m->folder!='' AND $m->show_on_menu AND !in_array($m->folder, $folders) )
            $folders[] = $m->folder;
      }
      return $folders;
   }
   
   public function pages($f='')
   {
      $pages = array();
      foreach($this->menu as $p)
      {
         if($f == $p->folder AND $p->show_on_menu AND !in_array($p, $pages) )
            $pages[] = $p;
      }
      return $pages;
   }
   
   protected function process()
   {
      
   }
   
   public function version()
   {
      return '0.9.24';
   }
   
   public function select_default_page()
   {
      if( $this->db->connected() )
      {
         if( $this->user->logged_on )
         {
            $url = FALSE;
            
            if( is_null($this->user->fs_page) )
            {
               $url = 'index.php?page=admin_pages';
               foreach($this->menu as $p)
               {
                  if($p->show_on_menu)
                  {
                     $url = $p->url() . '&show_dpa=TRUE';
                     break;
                  }
               }
            }
            else
               $url = 'index.php?page=' . $this->user->fs_page;
            
            Header('location: '.$url);
         }
      }
   }
   
   public function show_default_page_advice()
   {
      return isset($_GET['show_dpa']);
   }
   
   private function set_css_file()
   {
      if( isset($_GET['css_file']) )
      {
         if( file_exists('view/css/'.$_GET['css_file']) )
         {
            $this->css_file = $_GET['css_file'];
            setcookie('css_file', $_GET['css_file'], time()+FS_COOKIES_EXPIRE);
         }
         else
         {
            $this->new_error_msg("Archivo CSS no encontrado.");
            $this->css_file = 'base.css';
         }
      }
      else if( isset($_COOKIE['css_file']) )
      {
         if( file_exists('view/css/'.$_COOKIE['css_file']) )
            $this->css_file = $_COOKIE['css_file'];
         else
         {
            $this->new_error_msg("Archivo CSS no encontrado.");
            $this->css_file = 'base.css';
            setcookie('css_file', $this->css_file, time()+FS_COOKIES_EXPIRE);
         }
      }
      else
         $this->css_file = 'base.css';
   }
   
   public function is_admin_page()
   {
      return $this->admin_page;
   }
   
   private function set_default_items()
   {
      /// gestionamos la página de inicio
      if( isset($_GET['default_page']) )
      {
         $this->default_items->set_default_page( $this->page->name );
         $this->user->fs_page = $this->page->name;
         $this->user->save();
      }
      else if( is_null($this->default_items->default_page()) )
         $this->default_items->set_default_page( $this->user->fs_page );
      
      if( is_null($this->default_items->showing_page()) )
         $this->default_items->set_showing_page( $this->page->name );
      
      /*
       * Establecemos los elementos por defecto, pero no se guardan.
       * Para guardarlos hay que usar las funciones fs_controller::save_lo_que_sea().
       * La clase fs_default_items sólo se usa para indicar valores
       * por defecto a los modelos.
       */
      $this->default_items->set_codejercicio( $this->user->codejercicio );
      
      if( isset($_COOKIE['default_almacen']) )
         $this->default_items->set_codalmacen( $_COOKIE['default_almacen'] );
      else
         $this->default_items->set_codalmacen( $this->empresa->codalmacen );
      
      if( isset($_COOKIE['default_cliente']) )
         $this->default_items->set_codcliente( $_COOKIE['default_cliente'] );
      
      if( isset($_COOKIE['default_divisa']) )
         $this->default_items->set_coddivisa( $_COOKIE['default_divisa'] );
      else
         $this->default_items->set_coddivisa( $this->empresa->coddivisa );
      
      if( isset($_COOKIE['default_familia']) )
         $this->default_items->set_codfamilia( $_COOKIE['default_familia'] );
      
      if( isset($_COOKIE['default_formapago']) )
         $this->default_items->set_codpago( $_COOKIE['default_formapago'] );
      else
         $this->default_items->set_codpago( $this->empresa->codpago );
      
      if( isset($_COOKIE['default_impuesto']) )
         $this->default_items->set_codimpuesto( $_COOKIE['default_impuesto'] );
      
      if( isset($_COOKIE['default_pais']) )
         $this->default_items->set_codpais( $_COOKIE['default_pais'] );
      else
         $this->default_items->set_codpais( $this->empresa->codpais );
      
      if( isset($_COOKIE['default_proveedor']) )
         $this->default_items->set_codproveedor( $_COOKIE['default_proveedor'] );
      
      if( isset($_COOKIE['default_serie']) )
         $this->default_items->set_codserie( $_COOKIE['default_serie'] );
      else
         $this->default_items->set_codserie( $this->empresa->codserie );
   }
   
   protected function save_codejercicio($cod)
   {
      if($cod != $this->user->codejercicio)
      {
         $this->default_items->set_codejercicio($cod);
         $this->user->codejercicio = $cod;
         if( !$this->user->save() )
         {
            $this->new_error_msg('Error al establecer el ejercicio '.$cod.
               ' como ejercicio predeterminado para este usuario.');
         }
      }
   }
   
   protected function save_codalmacen($cod)
   {
      setcookie('default_almacen', $cod, time()+FS_COOKIES_EXPIRE);
      $this->default_items->set_codalmacen($cod);
   }
   
   protected function save_codcliente($cod)
   {
      setcookie('default_cliente', $cod, time()+FS_COOKIES_EXPIRE);
      $this->default_items->set_codcliente($cod);
   }
   
   protected function save_coddivisa($cod)
   {
      setcookie('default_divisa', $cod, time()+FS_COOKIES_EXPIRE);
      $this->default_items->set_coddivisa($cod);
   }
   
   protected function save_codfamilia($cod)
   {
      setcookie('default_familia', $cod, time()+FS_COOKIES_EXPIRE);
      $this->default_items->set_codfamilia($cod);
   }
   
   protected function save_codpago($cod)
   {
      setcookie('default_formapago', $cod, time()+FS_COOKIES_EXPIRE);
      $this->default_items->set_codpago($cod);
   }
   
   protected function save_codimpuesto($cod)
   {
      setcookie('default_impuesto', $cod, time()+FS_COOKIES_EXPIRE);
      $this->default_items->set_codimpuesto($cod);
   }
   
   protected function save_codpais($cod)
   {
      setcookie('default_pais', $cod, time()+FS_COOKIES_EXPIRE);
      $this->default_items->set_codpais($cod);
   }
   
   protected function save_codproveedor($cod)
   {
      setcookie('default_proveedor', $cod, time()+FS_COOKIES_EXPIRE);
      $this->default_items->set_codproveedor($cod);
   }
   
   protected function save_codserie($cod)
   {
      setcookie('default_serie', $cod, time()+FS_COOKIES_EXPIRE);
      $this->default_items->set_codserie($cod);
   }
   
   public function today()
   {
      return date('d-m-Y');
   }
}

?>
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

require_once 'base/fs_model.php';
require_once 'model/albaran_cliente.php';
require_once 'model/albaran_proveedor.php';
require_once 'model/familia.php';
require_once 'model/impuesto.php';
require_once 'model/tarifa.php';
require_once 'model/stock.php';

class articulo extends fs_model
{
   public $referencia;
   public $codfamilia;
   public $descripcion;
   public $pvp;
   public $pvp_ant;
   public $factualizado;
   public $codimpuesto;
   public $iva;
   public $destacado;
   public $bloqueado;
   public $secompra;
   public $sevende;
   public $equivalencia;
   public $stockfis;
   public $stockmin;
   public $stockmax;
   public $controlstock; /// permitir ventas sin stock
   public $codbarras;
   public $observaciones;
   
   private $imagen;
   private $has_imagen;
   
   private static $impuestos;
   private static $search_tags;
   
   public function __construct($a=FALSE)
   {
      parent::__construct('articulos');
      
      if( !isset(self::$impuestos) )
         self::$impuestos = array();
      
      if($a)
      {
         $this->referencia = $a['referencia'];
         $this->codfamilia = $a['codfamilia'];
         $this->descripcion = $this->no_html($a['descripcion']);
         $this->pvp = floatval($a['pvp']);
         $this->factualizado = Date('d-m-Y', strtotime($a['factualizado']));
         $this->codimpuesto = $a['codimpuesto'];
         $this->stockfis = floatval($a['stockfis']);
         $this->stockmin = floatval($a['stockmin']);
         $this->stockmax = floatval($a['stockmax']);
         $this->controlstock = ($a['controlstock'] == 't');
         $this->destacado = ($a['destacado'] == 't');
         $this->bloqueado = ($a['bloqueado'] == 't');
         $this->secompra = ($a['secompra'] == 't');
         $this->sevende = ($a['sevende'] == 't');
         $this->equivalencia = $a['equivalencia'];
         $this->codbarras = $a['codbarras'];
         $this->observaciones = $this->no_html($a['observaciones']);
         
         /// no cargamos la imágen directamente por cuestión de rendimiento
         $this->imagen = NULL;
         if( isset($a['imagen']) )
            $this->has_imagen = TRUE;
         else
            $this->has_imagen = FALSE;
      }
      else
      {
         $this->referencia = NULL;
         $this->codfamilia = NULL;
         $this->descripcion = '';
         $this->pvp = 0;
         $this->factualizado = Date('d-m-Y');
         $this->codimpuesto = NULL;
         $this->stockfis = 0;
         $this->stockmin = 0;
         $this->stockmax = 0;
         $this->controlstock = TRUE;
         $this->destacado = FALSE;
         $this->bloqueado = FALSE;
         $this->secompra = TRUE;
         $this->sevende = TRUE;
         $this->equivalencia = NULL;
         $this->codbarras = '';
         $this->observaciones = '';
         
         $this->imagen = NULL;
         $this->has_imagen = FALSE;
      }
      $this->pvp_ant = 0;
      $this->iva = NULL;
   }
   
   protected function install()
   {
      /// la tabla articulos tiene claves ajeas a familias e impuestos
      new familia();
      new impuesto();
      
      $this->clean_cache();
      
      /// borramos todas las imágenes de artículos
      if( file_exists('tmp/articulos') )
      {
         foreach(glob('tmp/articulos/*') as $file)
         {
            if( is_file($file) )
               unlink($file);
         }
      }
      
      return '';
   }
   
   public function get_descripcion_64()
   {
      return base64_encode($this->descripcion);
   }
   
   public function show_pvp()
   {
      return number_format($this->pvp, 2, '.', ' ');
   }
   
   public function show_pvp_iva($coma=TRUE)
   {
      if($coma)
         return number_format($this->pvp * (100+$this->get_iva()) / 100, 2, '.', ' ');
      else
         return number_format($this->pvp * (100+$this->get_iva()) / 100, 2, '.', '');
   }
   
   public function url()
   {
      if( is_null($this->referencia) )
         return "index.php?page=general_articulos";
      else
         return "index.php?page=general_articulo&ref=".$this->referencia;
   }
   
   public function get($ref)
   {
      $art = $this->db->select("SELECT * FROM ".$this->table_name.
              " WHERE referencia = ".$this->var2str($ref).";");
      if($art)
         return new articulo($art[0]);
      else
         return FALSE;
   }
   
   public function get_familia()
   {
      $fam = new familia();
      return $fam->get($this->codfamilia);
   }
   
   public function get_stock()
   {
      $stock = new stock();
      return $stock->all_from_articulo($this->referencia);
   }
   
   public function get_impuesto()
   {
      $imp = new impuesto();
      return $imp->get($this->codimpuesto);
   }
   
   public function get_iva()
   {
      if( is_null($this->iva) )
      {
         $encontrado = FALSE;
         foreach(self::$impuestos as $i)
         {
            if($i->codimpuesto == $this->codimpuesto)
            {
               $this->iva = floatval($i->iva);
               $encontrado = TRUE;
               break;
            }
         }
         if( !$encontrado )
         {
            $imp = new impuesto();
            $imp0 = $imp->get($this->codimpuesto);
            if($imp0)
            {
               $this->iva = floatval($imp0->iva);
               self::$impuestos[] = $imp0;
            }
            else
               $this->iva = 0;
         }
      }
      return $this->iva;
   }
   
   public function get_equivalentes()
   {
      $artilist = array();
      if( isset($this->equivalencia) )
      {
         $articulos = $this->db->select("SELECT * FROM ".$this->table_name.
                 " WHERE equivalencia = ".$this->var2str($this->equivalencia).
                 " ORDER BY referencia ASC;");
         if($articulos)
         {
            foreach($articulos as $a)
            {
               if($a['referencia'] != $this->referencia)
                  $artilist[] = new articulo($a);
            }
         }
      }
      return $artilist;
   }
   
   /*
    * Devuelve un array con las tarifas asignadas a ese artículo.
    * Si todas = TRUE -> devuelve además las que no están asignadas.
    */
   public function get_tarifas($todas=FALSE)
   {
      $tarifa = new tarifa();
      $tarifas = $tarifa->all();
      $tarifa_articulo = new tarifa_articulo();
      $tas = $tarifa_articulo->all_from_articulo( $this->referencia );
      if( $todas )
      {
         foreach($tarifas as $t)
         {
            $encontrada = FALSE;
            foreach($tas as $ta)
            {
               if( $ta->codtarifa == $t->codtarifa )
               {
                  $encontrada = TRUE;
                  break;
               }
            }
            if( !$encontrada )
            {
               /// añadimos las tarifas que no tiene asignadas
               $tas[] = new tarifa_articulo( array('id' => NULL, 'codtarifa' => $t->codtarifa,
                   'referencia' => $this->referencia, 'descuento' => 0 - $t->incporcentual) );
            }
         }
      }
      /// rellenamos las tarifas
      foreach($tas as $ta)
      {
         foreach($tarifas as $t)
         {
            if( $t->codtarifa == $ta->codtarifa )
            {
               $ta->nombre = $t->nombre;
               $ta->pvp = $this->pvp;
               $ta->iva = $this->get_iva();
               break;
            }
         }
      }
      return $tas;
   }
   
   public function get_lineas_albaran_cli($offset=0, $limit=FS_ITEM_LIMIT)
   {
      $linea = new linea_albaran_cliente();
      return $linea->all_from_articulo($this->referencia, $offset, $limit);
   }
   
   public function get_lineas_albaran_prov($offset=0, $limit=FS_ITEM_LIMIT)
   {
      $linea = new linea_albaran_proveedor();
      return $linea->all_from_articulo($this->referencia, $offset, $limit);
   }
   
   public function imagen_url()
   {
      if( $this->has_imagen )
      {
         if( file_exists('tmp/articulos/'.$this->referencia.'.png') )
            return '../tmp/articulos/'.$this->referencia.'.png';
         else
         {
            if( is_null($this->imagen) )
            {
               $imagen = $this->db->select("SELECT imagen FROM ".$this->table_name.
                       " WHERE referencia = ".$this->var2str($this->referencia).";");
               if($imagen)
                  $this->imagen = $this->str2bin($imagen[0]['imagen']);
               else
                  $this->has_imagen = FALSE;
            }
            
            if( isset($this->imagen) )
            {
               if( !file_exists('tmp/articulos') )
                  mkdir('tmp/articulos');
               
               $f = fopen('tmp/articulos/'.$this->referencia.'.png', 'a');
               fwrite($f, $this->imagen);
               fclose($f);
               return '../tmp/articulos/'.$this->referencia.'.png';
            }
            else
               return FALSE;
         }
      }
      else
         return FALSE;
   }
   
   public function set_imagen($img)
   {
      $this->imagen = $img;
      $this->has_imagen = TRUE;
   }
   
   public function set_pvp($p)
   {
      $this->pvp_ant = $this->pvp;
      $this->factualizado = Date('d-m-Y');
      $this->pvp = round($p, 3);
   }
   
   public function set_pvp_iva($p)
   {
      $this->pvp_ant = $this->pvp;
      $this->factualizado = Date('d-m-Y');
      $this->pvp = round((100*$p)/(100+$this->get_iva()), 3);
   }
   
   public function set_stock($almacen, $cantidad=1)
   {
      $result = FALSE;
      $stock = new stock();
      $encontrado = FALSE;
      $stocks = $stock->all_from_articulo($this->referencia);
      foreach($stocks as $k => $value)
      {
         if($value->codalmacen == $almacen)
         {
            $stocks[$k]->set_cantidad($cantidad);
            $result = $stocks[$k]->save();
            $encontrado = TRUE;
         }
      }
      if( !$encontrado )
      {
         $stock->referencia = $this->referencia;
         $stock->codalmacen = $almacen;
         $stock->set_cantidad($cantidad);
         $result = $stock->save();
      }
      if($result)
      {
         $nuevo_stock = $stock->total_from_articulo($this->referencia);
         if($this->stockfis != $nuevo_stock)
         {
            $this->stockfis =  $nuevo_stock;
            if( !$this->save() )
               $this->new_error_msg("Error al actualizar el stock del artículo");
         }
      }
      else
         $this->new_error_msg("Error al guardar el stock");
      return $result;
   }
   
   public function sum_stock($almacen, $cantidad=1)
   {
      $result = FALSE;
      $stock = new stock();
      $encontrado = FALSE;
      $stocks = $stock->all_from_articulo($this->referencia);
      foreach($stocks as $k => $value)
      {
         if($value->codalmacen == $almacen)
         {
            $stocks[$k]->sum_cantidad($cantidad);
            $result = $stocks[$k]->save();
            $encontrado = TRUE;
            break;
         }
      }
      if( !$encontrado )
      {
         $stock->referencia = $this->referencia;
         $stock->codalmacen = $almacen;
         $stock->set_cantidad($cantidad);
         $result = $stock->save();
      }
      if($result)
      {
         $nuevo_stock = $stock->total_from_articulo($this->referencia);
         if($this->stockfis != $nuevo_stock)
         {
            $this->stockfis =  $nuevo_stock;
            if( !$this->save() )
               $this->new_error_msg("¡Error al actualizar el stock del artículo!");
         }
      }
      else
         $this->new_error_msg("¡Error al guardar el stock!");
      return $result;
   }
   
   public function exists()
   {
      if( is_null($this->referencia) )
         return FALSE;
      else
         return $this->db->select("SELECT * FROM ".$this->table_name.
                 " WHERE referencia = ".$this->var2str($this->referencia).";");
   }
   
   public function test()
   {
      $status = FALSE;
      
      /// cargamos la imágen si todavía no lo habíamos hecho
      if( $this->has_imagen )
      {
         if( is_null($this->imagen) )
         {
            $imagen = $this->db->select("SELECT imagen FROM ".$this->table_name."
               WHERE referencia = ".$this->var2str($this->referencia).";");
            if($imagen)
               $this->imagen = $this->str2bin($imagen[0]['imagen']);
            else
            {
               $this->imagen = NULL;
               $this->has_imagen = FALSE;
            }
         }
      }
      
      $this->referencia = str_replace(' ', '_', trim($this->referencia));
      
      $this->descripcion = $this->no_html($this->descripcion);
      if( strlen($this->descripcion) > 100 )
         $this->descripcion = substr($this->descripcion, 0, 99);
      
      $this->equivalencia = str_replace(' ', '_', trim($this->equivalencia));
      $this->codbarras = $this->no_html($this->codbarras);
      $this->observaciones = $this->no_html($this->observaciones);
      
      if($this->equivalencia == '')
      {
         $this->equivalencia = NULL;
         $this->destacado = FALSE;
      }
      
      if( !preg_match("/^[A-Z0-9_\+\.\*\/\-]{1,18}$/i", $this->referencia) )
      {
         $this->new_error_msg("¡Referencia de artículo no válida! Debe tener entre 1 y 18 caracteres.
            Se admiten letras (excepto Ñ), números, '_', '.', '*', '/' ó '-'.");
      }
      else if( isset($this->equivalencia) AND !preg_match("/^[A-Z0-9_\+\.\*\/\-]{1,18}$/i", $this->equivalencia) )
      {
         $this->new_error_msg("¡Código de equivalencia del artículos no válido! Debe tener entre 1 y 18 caracteres.
            Se admiten letras (excepto Ñ), números, '_', '.', '*', '/' ó '-'.");
      }
      else
         $status = TRUE;
      
      return $status;
   }
   
   public function save()
   {
      if( $this->test() )
      {
         $this->clean_cache();
         $this->clean_image_cache();
         
         if( $this->exists() )
         {
            $sql = "UPDATE ".$this->table_name." SET descripcion = ".$this->var2str($this->descripcion).",
               codfamilia = ".$this->var2str($this->codfamilia).", pvp = ".$this->var2str($this->pvp).",
               factualizado = ".$this->var2str($this->factualizado).", codimpuesto = ".$this->var2str($this->codimpuesto).",
               stockfis = ".$this->var2str($this->stockfis).", stockmin = ".$this->var2str($this->stockmin).",
               stockmax = ".$this->var2str($this->stockmax).",
               controlstock = ".$this->var2str($this->controlstock).", destacado = ".$this->var2str($this->destacado).",
               bloqueado = ".$this->var2str($this->bloqueado).", sevende = ".$this->var2str($this->sevende).",
               secompra = ".$this->var2str($this->secompra).", equivalencia = ".$this->var2str($this->equivalencia).",
               codbarras = ".$this->var2str($this->codbarras).", observaciones = ".$this->var2str($this->observaciones).",
               imagen = ".$this->bin2str($this->imagen)." WHERE referencia = ".$this->var2str($this->referencia).";";
         }
         else
         {
            $sql = "INSERT INTO ".$this->table_name." (referencia,codfamilia,descripcion,pvp,factualizado,codimpuesto,stockfis,
               stockmin,stockmax,controlstock,destacado,bloqueado,secompra,sevende,equivalencia,codbarras,observaciones,imagen)
               VALUES (".$this->var2str($this->referencia).",".$this->var2str($this->codfamilia).",".$this->var2str($this->descripcion).",
               ".$this->var2str($this->pvp).",".$this->var2str($this->factualizado).",".$this->var2str($this->codimpuesto).",
               ".$this->var2str($this->stockfis).",".$this->var2str($this->stockmin).",".$this->var2str($this->stockmax).",
               ".$this->var2str($this->controlstock).",".$this->var2str($this->destacado).",".$this->var2str($this->bloqueado).",
               ".$this->var2str($this->secompra).",".$this->var2str($this->sevende).",".$this->var2str($this->equivalencia).",
               ".$this->var2str($this->codbarras).",".$this->var2str($this->observaciones).",".$this->bin2str($this->imagen).");";
         }
         return $this->db->exec($sql);
      }
      else
         return FALSE;
   }
   
   public function delete()
   {
      $this->clean_cache();
      $this->clean_image_cache();
      
      return $this->db->exec("DELETE FROM ".$this->table_name.
              " WHERE referencia = ".$this->var2str($this->referencia).";");
   }
   
   private function new_search_tag($tag)
   {
      $encontrado = FALSE;
      $actualizar = FALSE;
      
      if( strlen($tag) > 0 )
      {
         /// obtenemos los datos de memcache
         $this->get_search_tags();
         
         foreach(self::$search_tags as $i => $value)
         {
            if( $value['tag'] == $tag )
            {
               $encontrado = TRUE;
               if( time()+86400 > $value['expires']+300 )
               {
                  self::$search_tags[$i]['expires'] = time()+86400;
                  $actualizar = TRUE;
               }
               break;
            }
         }
         if( !$encontrado )
         {
            self::$search_tags[] = array('tag' => $tag, 'expires' => time()+86400);
            $actualizar = TRUE;
         }
         
         if( $actualizar )
            $this->cache->set('articulos_searches', self::$search_tags, 86400);
      }
      
      return $encontrado;
   }
   
   public function get_search_tags()
   {
      if( !isset(self::$search_tags) )
         self::$search_tags = $this->cache->get_array('articulos_searches');
      return self::$search_tags;
   }
   
   public function cron_job()
   {
      /// obtenemos los datos de memcache
      $this->get_search_tags();
      
      if( self::$search_tags )
      {
         foreach(self::$search_tags as $i => $value)
         {
            if( $value['expires'] < time() )
            {
               /// eliminamos las búsquedas antiguas
               unset(self::$search_tags[$i]);
            }
            else
            {
               /// guardamos los resultados de la búsqueda en memcache
               $this->cache->set('articulos_search_'.$value['tag'], $this->search($value['tag']), 3600);
            }
         }
         
         $this->cache->set('articulos_searches', self::$search_tags, 86400);
      }
   }
   
   private function clean_image_cache()
   {
      if( file_exists('tmp/articulos/'.$this->referencia.'.png') )
         unlink('tmp/articulos/'.$this->referencia.'.png');
   }
   
   private function clean_cache()
   {
      /// obtenemos los datos de memcache
      $this->get_search_tags();
      
      if( self::$search_tags )
      {
         foreach(self::$search_tags as $value)
            $this->cache->delete('articulos_search_'.$value['tag']);
      }
      
      /// eliminamos también la cache de tpv_yamyam
      $this->cache->delete('tpv_yamyam_articulos');
   }
   
   public function search($query, $offset=0, $codfamilia='', $con_stock=FALSE)
   {
      $artilist = array();
      $query = $this->no_html( strtolower($query) );
      
      if($offset == 0 AND $codfamilia == '' AND !$con_stock)
      {
         /// intentamos obtener los datos de memcache
         if( $this->new_search_tag($query) )
            $artilist = $this->cache->get_array('articulos_search_'.$query);
      }
      
      if( count($artilist) == 0 )
      {
         if($codfamilia == '')
            $sql = "SELECT * FROM ".$this->table_name." WHERE ";
         else
            $sql = "SELECT * FROM ".$this->table_name." WHERE codfamilia = ".$this->var2str($codfamilia)." AND ";
         
         if($con_stock)
            $sql .= "stockfis > 0 AND ";
         
         if( is_numeric($query) )
         {
            $sql .= "(referencia ~~ '%".$query."%' OR equivalencia ~~ '%".$query."%' OR descripcion ~~ '%".$query."%'
               OR codbarras = '".$query."')";
         }
         else
         {
            $buscar = str_replace(' ', '%', $query);
            $sql .= "(lower(referencia) ~~ '%".$buscar."%' OR lower(equivalencia) ~~ '%".$buscar."%'
               OR lower(descripcion) ~~ '%".$buscar."%')";
         }
         
         $sql .= " ORDER BY referencia ASC";
         
         $articulos = $this->db->select_limit($sql, FS_ITEM_LIMIT, $offset);
         if($articulos)
         {
            foreach($articulos as $a)
               $artilist[] = new articulo($a);
         }
      }
      
      return $artilist;
   }
   
   public function multiplicar_precios($codfam, $m=1)
   {
      if( isset($codfam) AND $m != 1 )
      {
         $this->clean_cache();
         
         return $this->db->exec("UPDATE ".$this->table_name." SET pvp = (pvp*".floatval($m).")
            WHERE codfamilia = ".$this->var2str($codfam).";");
      }
      else
         return TRUE;
   }
   
   public function all($offset=0, $limit=FS_ITEM_LIMIT)
   {
      $artilist = array();
      $articulos = $this->db->select_limit("SELECT * FROM ".$this->table_name.
              " ORDER BY referencia ASC", $limit, $offset);
      if($articulos)
      {
         foreach($articulos as $a)
            $artilist[] = new articulo($a);
      }
      return $artilist;
   }
   
   public function all_from_familia($codfamilia, $offset=0, $limit=FS_ITEM_LIMIT)
   {
      $artilist = array();
      $articulos = $this->db->select_limit("SELECT * FROM ".$this->table_name.
              " WHERE codfamilia = ".$this->var2str($codfamilia).
              " ORDER BY referencia ASC", $limit, $offset);
      if($articulos)
      {
         foreach($articulos as $a)
            $artilist[] = new articulo($a);
      }
      return $artilist;
   }
   
   public function count($codfamilia=FALSE)
   {
      $num = 0;
      if( $codfamilia )
      {
         $articulos = $this->db->select("SELECT COUNT(*) as total FROM ".$this->table_name.
                 " WHERE codfamilia = ".$this->var2str($codfamilia).";");
      }
      else
         $articulos = $this->db->select("SELECT COUNT(*) as total FROM ".$this->table_name.";");
      if($articulos)
         $num = intval($articulos[0]['total']);
      return $num;
   }
   
   public function move_codimpuesto($cod0, $cod1, $mantener=FALSE)
   {
      if($mantener)
      {
         $this->clean_cache();
         
         $impuesto = new impuesto();
         $impuesto0 = $impuesto->get($cod0);
         $impuesto1 = $impuesto->get($cod1);
         $multiplo = (100 + $impuesto0->iva) / (100 + $impuesto1->iva);
         return $this->db->exec("UPDATE ".$this->table_name." SET codimpuesto = ".$this->var2str($cod1).
                 ", pvp = (pvp*".$multiplo.") WHERE codimpuesto = ".$this->var2str($cod0).";");
      }
      else
         return $this->db->exec("UPDATE ".$this->table_name." SET codimpuesto = ".$this->var2str($cod1).
                 " WHERE codimpuesto = ".$this->var2str($cod0).";");
   }
}

?>

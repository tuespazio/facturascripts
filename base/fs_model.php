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

require_once 'base/bround.php';
require_once 'base/fs_cache.php';
require_once 'base/fs_db.php';
require_once 'base/fs_default_items.php';

abstract class fs_model
{
   protected $db;
   protected $table_name;
   protected $cache;
   protected $default_items;
   
   private static $checked_tables;
   private static $errors;
   
   public function __construct($name = '')
   {
      $this->db = new fs_db();
      $this->table_name = $name;
      $this->cache = new fs_cache();
      $this->default_items = new fs_default_items();
      
      if( !self::$errors )
         self::$errors = array();
      
      if( !self::$checked_tables )
      {
         if( $this->cache->error() )
         {
            $this->new_error_msg( 'Memcache está deshabilitado. '.$this->cache->error_msg() );
            self::$checked_tables = array();
         }
         else
         {
            self::$checked_tables = $this->cache->get_array('fs_checked_tables');
            if( self::$checked_tables )
            {
               /// nos aseguramos de que existan todas las tablas que se suponen comprobadas
               $tables = $this->db->list_tables();
               foreach(self::$checked_tables as $ct)
               {
                  $found = FALSE;
                  foreach($tables as $t)
                  {
                     if($ct == $t['name'])
                     {
                        $found = TRUE;
                        break;
                     }
                  }
                  if( !$found )
                  {
                     $this->clean_checked_tables();
                     break;
                  }
               }
            }
         }
      }
      
      if($name != '')
      {
         if( !in_array($name, self::$checked_tables) )
         {
            if( $this->check_table($name) )
            {
               self::$checked_tables[] = $name;
               $this->cache->set('fs_checked_tables', self::$checked_tables);
            }
         }
      }
   }
   
   protected function clean_checked_tables()
   {
      self::$checked_tables = array();
      $this->cache->delete('fs_checked_tables');
   }
   
   protected function new_error_msg($msg = FALSE)
   {
      if( $msg )
         self::$errors[] = $msg;
   }
   
   public function get_errors()
   {
      return self::$errors;
   }
   
   /*
    * Esta función es llamada al crear una tabla.
    * Permite insertar tuplas o lo que desees.
    */
   abstract protected function install();
   
   /*
    * Esta función devuelve TRUE si los datos del objeto se encuentran
    * en la base de datos.
    */
   abstract public function exists();
   
   /// Esta función devuelve TRUE si los datos del objeto son válidos
   abstract public function test();
   
   /*
    * Esta función sirve tanto para insertar como para actualizar
    * los datos del objeto en la base de datos.
    */
   abstract public function save();
   
   /// Esta función sirve para eliminar los datos del objeto de la base de datos
   abstract public function delete();
   
   public function escape_string($s='')
   {
      return $this->db->escape_string($s);
   }
   
   public function var2str($v)
   {
      if( is_null($v) )
         return 'NULL';
      else if( is_bool($v) )
      {
         if($v)
            return 'TRUE';
         else
            return 'FALSE';
      }
      else
         return "'" . $this->db->escape_string($v) . "'";
   }
   
   public function bin2str($v)
   {
      if( is_null($v) )
         return 'NULL';
      else
         return "'".bin2hex($v)."'";
   }
   
   public function hex2bin($data)
   {
      $bin = "";
      $i = 0;
      do {
         $bin .= chr(hexdec($data{$i}.$data{($i + 1)}));
         $i += 2;
      } while($i < strlen($data));
      return $bin;
   }
   
   public function str2bin($v)
   {
      if( is_null($v) )
         return NULL;
      else
         return $this->hex2bin($v);
   }
   
   public function intval($s)
   {
      if( is_null($s) )
         return NULL;
      else
         return intval($s);
   }
   
   /*
    * Compara dos números en coma flotante con una precisión de $precision,
    * devuelve TRUE si son iguales, FALSE en caso contrario.
    */
   public function floatcmp($f1, $f2, $precision = 10, $round = FALSE)
   {
      if($round)
         return( abs($f1-$f2) < 6/pow(10,$precision+1) );
      else
         return( bccomp( (string)$f1, (string)$f2, $precision ) == 0 );
   }
   
   /*
    * Compara tres números en coma flotante usando la función floatcmp().
    * Compara el primer número con el segundo y con el tercero, si es
    * igual a alguno de ellos devuelve TRUE.
    */
   public function floatcmp3($f1, $f2, $f3, $precision = 10, $round = FALSE)
   {
      if( $this->floatcmp($f1, $f2, $precision, $round) )
         return TRUE;
      else if( $this->floatcmp($f1, $f3, $precision, $round) )
         return TRUE;
      else
         return FALSE;
   }
   
   /// functión auxiliar para facilitar el uso de fechas
   public function var2timesince($v)
   {
      if( isset($v) )
      {
         $v = strtotime($v);
         $time = time() - $v;
         
         if($time <= 60)
            return 'hace '.round($time/60,0).' segundos';
         else if(60 < $time && $time <= 3600)
            return 'hace '.round($time/60,0).' minutos';
         else if(3600 < $time && $time <= 86400)
            return 'hace '.round($time/3600,0).' horas';
         else if(86400 < $time && $time <= 604800)
            return 'hace '.round($time/86400,0).' dias';
         else if(604800 < $time && $time <= 2592000)
            return 'hace '.round($time/604800,0).' semanas';
         else if(2592000 < $time && $time <= 29030400)
            return 'hace '.round($time/2592000,0).' meses';
         else if($time > 29030400)
            return 'hace más de un año';
      }
      else
         return 'fecha desconocida';
   }
   
   /*
    * Esta función convierte:
    * < en &lt;
    * > en &gt;
    * " en &quot;
    * ' en &#39;
    * 
    * No tengas la tentación de sustiturla por htmlentities o htmlspecialshars
    * porque te encontrarás con muchas sorpresas desagradables.
    */
   public function no_html($t)
   {
      $newt = preg_replace('/</', '&lt;', $t);
      $newt = preg_replace('/>/', '&gt;', $newt);
      $newt = preg_replace('/"/', '&quot;', $newt);
      $newt = preg_replace("/'/", '&#39;', $newt);
      return trim($newt);
   }
   
   /// obtiene las columnas y restricciones del fichero xml para una tabla
   private function get_xml_table(&$columnas, &$restricciones)
   {
      $retorno = TRUE;
      $xml = simplexml_load_file('model/table/' . $this->table_name . '.xml');
      if($xml)
      {
         if($xml->columna)
         {
            $i = 0;
            foreach($xml->columna as $col)
            {
               $columnas[$i]['nombre'] = $col->nombre;
               $columnas[$i]['tipo'] = $col->tipo;
               $columnas[$i]['nulo'] = $col->nulo;
               $columnas[$i]['defecto'] = $col->defecto;
               $i++;
            }
         }
         else /// debe de haber columnas, sino es un fallo
            $retorno = FALSE;
         
         if($xml->restriccion)
         {
            $i = 0;
            foreach($xml->restriccion as $col)
            {
               $restricciones[$i]['nombre'] = $col->nombre;
               $restricciones[$i]['consulta'] = $col->consulta;
               $i++;
            }
         }
      }
      else
         $retorno = FALSE;
      return($retorno);
   }
   
   /*
    * A partir del campo default del xml de una tabla
    * comprueba si se refiere a una secuencia, y si es así
    * comprueba la existencia de la secuencia. Si no la encuentra
    * la crea.
    */
   private function default2check_sequence($default, $colname)
   {
      /// ¿Se refiere a una secuencia?
      if( strtolower(substr($default, 0, 9)) == "nextval('" )
      {
         $aux = explode("'", $default);
         if( count($aux) == 3 )
         {
            /// ¿Existe esa secuencia?
            if( !$this->db->sequence_exists($aux[1]) )
            {
               /// ¿En qué número debería empezar esta secuencia?
               $num = 1;
               $aux_num = $this->db->select("SELECT MAX(".$colname."::integer) as num FROM ".$this->table_name.";");
               if($aux_num)
                  $num += intval($aux_num[0]['num']);
               $this->db->exec("CREATE SEQUENCE ".$aux[1]." START ".$num.";");
            }
         }
      }
   }
   
   /*
    * Compara dos arrays de columnas, devuelve una sentencia sql
    * en caso de encontrar diferencias.
    */
   private function compare_columns($xml_cols, $columnas)
   {
      $consulta = '';
      foreach($xml_cols as $col)
      {
         $encontrada = FALSE;
         if($columnas)
         {
            foreach($columnas as $col2)
            {
               if($col2['column_name'] == $col['nombre'])
               {
                  if($col['defecto'] == '')
                     $col['defecto'] = NULL;
                  if($col2['column_default'] != $col['defecto'])
                  {
                     if( is_null($col['defecto']) )
                        $consulta .= "ALTER TABLE ".$this->table_name.' ALTER COLUMN "'.$col['nombre'].'" DROP DEFAULT;';
                     else
                     {
                        $this->default2check_sequence($col['defecto'], $col['nombre']);
                        $consulta .= "ALTER TABLE ".$this->table_name.' ALTER COLUMN "'.
                             $col['nombre'].'" SET DEFAULT '.$col['defecto'].";";
                     }
                  }
                  if($col2['is_nullable'] != $col['nulo'])
                  {
                     if($col['nulo'] == "YES")
                        $consulta .= "ALTER TABLE ".$this->table_name.' ALTER COLUMN "'.$col['nombre'].'" DROP NOT NULL;';
                     else
                        $consulta .= "ALTER TABLE ".$this->table_name.' ALTER COLUMN "'.$col['nombre'].'" SET NOT NULL;';
                  }
                  $encontrada = TRUE;
                  break;
               }
            }
         }
         if(!$encontrada)
         {
            $consulta .= "ALTER TABLE ".$this->table_name.' ADD COLUMN "'.$col['nombre'].'" '.$col['tipo'];
            if($col['defecto'] != "")
               $consulta .= " DEFAULT ".$col['defecto'];
            if($col['nulo'] == "NO")
               $consulta .= " NOT NULL";
            $consulta .= ";\n";
         }
      }
      return $consulta;
   }

   /*
    * Compara dos arrays de restricciones, devuelve una sentencia sql
    * en caso de encontrar diferencias.
    */
   private function compare_constraints($c_nuevas, $c_old)
   {
      $consulta = "";
      if($c_old)
      {
         if($c_nuevas)
         {
            /// comprobamos una a una las viejas
            foreach($c_old as $col)
            {
               $encontrado = FALSE;
               foreach($c_nuevas as $col2)
               {
                  if($col['restriccion'] == $col2['nombre'])
                  {
                     $encontrado = TRUE;
                     break;
                  }
               }
               if(!$encontrado)
               {
                  /// eliminamos la restriccion
                  $consulta .= "ALTER TABLE " . $this->table_name . " DROP CONSTRAINT " . $col['restriccion'] . ";\n";
               }
            }

            /// comprobamos una a una las nuevas
            foreach($c_nuevas as $col)
            {
               $encontrado = FALSE;
               foreach($c_old as $col2)
               {
                  if($col['nombre'] == $col2['restriccion'])
                  {
                     $encontrado = TRUE;
                     break;
                  }
               }
               if(!$encontrado)
               {
                  /// añadimos la restriccion
                  $consulta .= "ALTER TABLE " . $this->table_name . " ADD CONSTRAINT " . $col['nombre'] . " " . $col['consulta'] . ";\n";
               }
            }
         }
         else
         {
            /// eliminamos todas las restricciones
            foreach($c_old as $col)
               $consulta .= "ALTER TABLE " . $this->table_name . " DROP CONSTRAINT " . $col['restriccion'] . ";\n";
         }
      }
      else if($c_nuevas)
      {
         /// añadimos todas las restricciones nuevas
         foreach($c_nuevas as $col)
            $consulta .= "ALTER TABLE " . $this->table_name . " ADD CONSTRAINT " . $col['nombre'] . " " . $col['consulta'] . ";\n";
      }
      return($consulta);
   }

   /// devuelve la sentencia sql necesaria para crear una tabla con la estructura proporcionada
   private function generate_table($xml_columnas, $xml_restricciones)
   {
      $consulta = "CREATE TABLE " . $this->table_name . " (\n";
      $i = FALSE;
      foreach($xml_columnas as $col)
      {
         /// añade la coma al final
         if($i)
            $consulta .= ",\n";
         else
            $i = TRUE;
         $consulta .= '"' . $col['nombre'] . '" ' . $col['tipo'];
         if($col['nulo'] == 'NO')
            $consulta .= " NOT NULL";
         if($col['defecto'] != "" AND !in_array($col['tipo'], array('serial', 'bigserial')))
            $consulta .= " DEFAULT " . $col['defecto'];
      }
      $consulta .= " );\n" . $this->compare_constraints($xml_restricciones, FALSE);
      return($consulta);
   }
   
   /// comprueba y actualiza la estructura de la tabla si es necesario
   private function check_table()
   {
      $done = TRUE;
      $consulta = "";
      $columnas = FALSE;
      $restricciones = FALSE;
      $xml_columnas = FALSE;
      $xml_restricciones = FALSE;
      
      if( $this->get_xml_table($xml_columnas, $xml_restricciones) )
      {
         if( $this->db->table_exists($this->table_name) )
         {
            /// comparamos las columnas
            $columnas = $this->db->get_columns($this->table_name);
            $consulta .= $this->compare_columns($xml_columnas, $columnas);
            
            /// comparamos las restricciones
            $restricciones = $this->db->get_constraints($this->table_name);
            $consulta .= $this->compare_constraints($xml_restricciones, $restricciones);
         }
         else
         {
            /// generamos el sql para crear la tabla
            $consulta .= $this->generate_table($xml_columnas, $xml_restricciones);
            $consulta .= $this->install();
         }
         
         if($consulta != '')
         {
            if( !$this->db->exec($consulta) )
            {
               $this->new_error_msg("Error. " . $consulta);
               $done = FALSE;
            }
         }
      }
      else
      {
         $this->new_error_msg("Error con el xml");
         $done = FALSE;
      }
      
      return $done;
   }
}

?>

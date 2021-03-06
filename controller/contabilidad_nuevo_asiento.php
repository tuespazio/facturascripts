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

require_once 'base/fs_cache.php';
require_once 'model/asiento.php';
require_once 'model/concepto_partida.php';
require_once 'model/divisa.php';
require_once 'model/ejercicio.php';
require_once 'model/impuesto.php';
require_once 'model/partida.php';
require_once 'model/subcuenta.php';

class contabilidad_nuevo_asiento extends fs_controller
{
   public $asiento;
   public $concepto;
   public $divisa;
   public $impuesto;
   public $ejercicio;
   public $resultados;
   public $subcuenta;

   public function __construct()
   {
      parent::__construct('contabilidad_nuevo_asiento', 'nuevo asiento', 'contabilidad', FALSE, FALSE);
   }
   
   protected function process()
   {
      $this->ppage = $this->page->get('contabilidad_asientos');
      
      $this->asiento = new asiento();
      $this->concepto = new concepto_partida();
      $this->divisa = new divisa();
      $this->impuesto = new impuesto();
      $this->ejercicio = new ejercicio();
      $this->subcuenta = new subcuenta();
      
      if( isset($_POST['fecha']) AND isset($_POST['query']) )
         $this->new_search();
      else if( isset($_POST['fecha']) AND isset($_POST['concepto']) AND isset($_POST['divisa']) )
      {
         $continuar = TRUE;
         
         $eje0 = $this->ejercicio->get_by_fecha($_POST['fecha']);
         if($eje0)
            $this->save_codejercicio($eje0->codejercicio);
         else
            $continuar = FALSE;
         
         $div0 = $this->divisa->get($_POST['divisa']);
         if($div0)
            $this->save_coddivisa($div0->coddivisa);
         else
            $continuar = FALSE;
         
         if($continuar)
         {
            $this->asiento->codejercicio = $eje0->codejercicio;
            $this->asiento->idconcepto = $_POST['idconceptopar'];
            $this->asiento->concepto = $_POST['concepto'];
            $this->asiento->fecha = $_POST['fecha'];
            $this->asiento->importe = $_POST['importe'];
            if( $this->asiento->save() )
            {
               $numlineas = intval($_POST['numlineas']);
               for($i=1; $i <= $numlineas; $i++)
               {
                  if( isset($_POST['codsubcuenta_'.$i]) )
                  {
                     if( $_POST['codsubcuenta_'.$i] != '' AND $continuar)
                     {
                        $sub0 = $this->subcuenta->get_by_codigo($_POST['codsubcuenta_'.$i], $eje0->codejercicio);
                        if($sub0)
                        {
                           $partida = new partida();
                           $partida->idasiento = $this->asiento->idasiento;
                           $partida->coddivisa = $div0->coddivisa;
                           $partida->tasaconv = $div0->tasaconv;
                           $partida->idsubcuenta = $sub0->idsubcuenta;
                           $partida->codsubcuenta = $sub0->codsubcuenta;
                           $partida->debe = $_POST['debe_'.$i];
                           $partida->haber = $_POST['haber_'.$i];
                           $partida->idconcepto = $this->asiento->idconcepto;
                           $partida->concepto = $this->asiento->concepto;
                           $partida->documento = $this->asiento->documento;
                           $partida->tipodocumento = $this->asiento->tipodocumento;
                           
                           if( isset($_POST['codcontrapartida_'.$i]) )
                           {
                              if( $_POST['codcontrapartida_'.$i] != '')
                              {
                                 $subc1 = $this->subcuenta->get_by_codigo($_POST['codcontrapartida_'.$i], $eje0->codejercicio);
                                 if($subc1)
                                 {
                                    $partida->idcontrapartida = $subc1->idsubcuenta;
                                    $partida->codcontrapartida = $subc1->codsubcuenta;
                                    $partida->cifnif = $_POST['cifnif_'.$i];
                                    $partida->iva = $_POST['iva_'.$i];
                                    $partida->baseimponible = $_POST['baseimp_'.$i];
                                 }
                                 else
                                 {
                                    $this->new_error_msg('Subcuenta '.$_POST['codcontrapartida_'.$i].' no encontrada.');
                                    $continuar = FALSE;
                                 }
                              }
                           }
                           
                           if( !$partida->save() )
                              $continuar = FALSE;
                        }
                        else
                        {
                           $this->new_error_msg('Subcuenta '.$_POST['codsubcuenta_'.$i].' no encontrada.');
                           $continuar = FALSE;
                        }
                     }
                  }
               }
               
               if( $continuar )
                  $this->new_message("<a href='".$this->asiento->url()."'>Asiento</a> guardado correctamente!");
               else
               {
                  if( $this->asiento->delete() )
                     $this->new_error_msg("¡Error en alguna de las partidas! Se ha borrado el asiento.");
                  else
                     $this->new_error_msg("¡Error en alguna de las partidas! Además ha sido imposible borrar el asiento.");
               }
            }
            else
               $this->new_error_msg("¡Imposible guardar el asiento!");
         }
         else
            $this->new_error_msg("Faltan datos.");
      }
   }
   
   public function version()
   {
      return parent::version().'-7';
   }
   
   private function new_search()
   {
      /// cambiamos la plantilla HTML
      $this->template = 'ajax/contabilidad_nuevo_asiento';
      
      $cache = new fs_cache();
      $eje0 = $this->ejercicio->get_by_fecha($_POST['fecha']);
      $this->resultados = $cache->get_array('search_subcuenta_ejercicio_'.$eje0->codejercicio.'_'.$this->query);
      if( count($this->resultados) < 1 )
      {
         $this->resultados = $this->subcuenta->search_by_ejercicio($eje0->codejercicio, $this->query);
         $cache->set('search_subcuenta_ejercicio_'.$eje0->codejercicio.'_'.$this->query, $this->resultados);
      }
   }
}

?>

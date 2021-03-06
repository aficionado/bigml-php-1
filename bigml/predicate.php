<?php
#
# Copyright 2012-2014 BigML
#
# Licensed under the Apache License, Version 2.0 (the "License"); you may
# not use this file except in compliance with the License. You may obtain
# a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
# WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
# License for the specific language governing permissions and limitations
# under the License.

function plural($text, $num) {
    /*
      Pluralizer: adds "s" at the end of a string if a given number is > 1
    */
   return $text . $num>1 ? 's' : '';
}

class Predicate {
   /*
      A predicate to be evaluated in a tree's node.
   */

   const TM_TOKENS = 'tokens_only';
   const TM_FULL_TERM = 'full_terms_only';
   const TM_ALL = 'all';
   const FULL_TERM_PATTERN = "/^.+\b.+$/u"; 

   
   private static $OPERATOR = array("<"=> "<",
                            "<="=> "<=",
                             "="=> "=",
                             "!="=> "!=",
                            ">="=> ">=",
                            ">"=>  ">");

   private static $RELATIONS = array('<=' => 'no more than %s %s', 
                  '>=' => '%s %s at most',
                   '>' => 'more than %s %s',
                   '<' => 'less than %s %s');

   public $operator;
   public $field;
   public $value;
   public $term;

   public function __construct($operator, $field, $value, $term=null) {
         $this->operator = $operator;
         $this->field = $field;
         $this->value = $value;
         $this->term = $term;
   }

   function is_full_term($fields) {
      /*
         Returns a boolean showing if a term is considered as a full_term
      */
      if ($this->term != null) {
         $options = $fields->{$this->field}->term_analysis;
         $token_mode = property_exists($options, 'token_mode') ? $options->token_mode : Predicate::TM_TOKENS;

         if ($token_mode == Predicate::TM_FULL_TERM ) {
            return true;
         } elseif ($token_mode == Predicate::TM_ALL)  {
            return preg_match(Predicate::FULL_TERM_PATTERN, $this->term);
         }

      }
      return false;
   }


   function to_rule($fields, $label='name') {
      /*
       Builds rule string from a predicate
      */
      $name=$fields->{$this->field}->{$label};
      $full_term = $this->is_full_term($fields);

      if ($this->term != null ) {

         $relation_suffix = '';
         $relation_literal = '';

         if ( ($this->operator == '<' && $this->value <= 1) || ($this->operator == '<=' && $this->value ==0) ) {
            $relation_literal = $full_term ? 'is not equal to' : 'does not contain';
         } else {
            $relation_literal = $full_term ? 'is equal to' : 'contains';
            if (!$full_term) {
               if ($this->operator != '>' || $this->value != 0) {
                  $relation_suffix = $this->RELATIONS[$this->operator] . $this->value . plural('time', $this->value);
               }
            }
         }

         return $name . " " . $relation_literal . " " . $this->term . " " . $relation_suffix;
      }

      return $name . " " . $this->operator . " ". $this->value;
   }

   function apply($input_data, $fields) {
      /*
         Applies the operators defined in the predicate as strings toi the provided input data
      */
      if ($this->term != null ) {
         $term_forms = property_exists($fields->{$this->field}->summary, 'term_forms') ? 
                     property_exists($fields->{$this->field}->summary->term_forms->{$this->term}) ? $fields->{$this->field}->summary->term_forms->{$this->term} 
                     : array() 
                    : array();

         $terms = array($this->term);
         $terms = array_merge($terms, $term_forms);
         $options = $fields->{$this->field}->$term_analysis;

         return version_compare($this->term_matches($input_data[$this->field], $terms, $options), $this->value, self::$OPERATOR[$this->operator]);
      }

      return version_compare($input_data[$this->field], $this->value, self::$OPERATOR[$this->operator]);
   }

   function term_matches($text, $forms_list, $options) {
      /*
         Counts the number of occurences of the words in forms_list in the text
         The terms in forms_list can either be tokens or full terms. The
         matching for tokens is contains and for full terms is equals.
      */

      $token_mode = property_exists($options, 'token_mode') ? $options->token_mode : Predicate::TM_TOKENS;
      $case_sensitive = property_exists($options, 'case_sensitive') ? $options->token_mode : false;
      $first_term = $forms_list[0];

      if ($token_mode == Predicate::TM_FULL_TERM) {
         return $this->full_term_match($text, $first_term, $case_sensitive);
      }

      # In token_mode='all' we will match full terms using equals and
      # # tokens using contains

      if ($token_mode == Predicate::TM_ALL && count($forms_list) == 1) {
         if ( preg_match(Predicate::FULL_TERM_PATTERN, $first_term) ) {
            return $this->full_term_match($text, $first_term, $case_sensitive);
         }
      }

      return $this->term_matches_tokens($text, $forms_list, $case_sensitive);
   }


   function full_term_match($text, $full_term, $case_sensitive) {
      /*
         Counts the match for full terms according to the case_sensitive option
      */
      if (!$case_sensitive) {
         $text = strtolower($text);
         $full_term = strtolower($full_term);
      }
      return ($text == $full_term) ? 1 : 0; 
   }

   function term_matches_tokens($text, $forms_list, $case_sensitive) {
      /*
         Counts the number of occurences of the words in forms_list in the text
      */
      $flags = $this->get_tokens_flags($case_sensitive);
      $expression = "/(\b|_)" . join("(\\b|_)|(\\b|_)",$forms_list) . "(\b|_)/" . $flags;

      preg_match_all($expression, $text, $matches);

      return count($matches);

   }

   function get_tokens_flags($case_sensitive) {
      /*
         Returns flags for regular expression matching depending on text analysis options
      */
      $flags = "u";
      if (!$case_sensitive) {
         $flags = "iu";
      }

      return $flags;
   }
}

?>

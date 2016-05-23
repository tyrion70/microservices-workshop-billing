<?php
/**
 * Standaard include voor functies
 *
 * @package PHProjekt
 * @subpackage Includes
 * @author P.P. van Mourik <pvmourik@brilmij.nl>
 * @version 1.0.2
 * @copyright Peter van Mourik
 * @since 01-09-2003
 * @change 2004-05-16 Commentaar toegevoegd
 * @change 2004-05-16 Opgeschoond
 * @change 2004-08-10 Alleen nog de class
 * @change 2005-08-02 Debug functionaliteit en tellen # queries toegevoegd
 */

/**
 * Database connectivity class
 *
 * Door het aanroepen van deze class wordt een MySQL connectie aangeroepen.
 * Gebruik de volgende code om deze te gebruiken:
 * <code>
 * new Connection
 * </code>
 * @package PHProjekt
 */
class Connection {
    /**
     * Service variabele
     * @access public
     * @var string
     */
    var $services = NULL;
    /**
     * Naam van de server
     * @access public
     * @var string
     */
    var $hostname = NULL;
    /**
     * Naam van de Database
     * @access public
     * @var string
     */
    var $database = NULL;
    /**
     * De username van de connectie
     * @access public
     * @var string
     */
    var $username = NULL;
    /**
     * Het password voor de connectie
     * @access public
     * @var string Het password
     */
    var $password = NULL;
    /**
     * Het ID van de Link
     * @access public
     * @var string
     */
    var $link_id = NULL;
    /**
     * Het ID van de Query
     * @access public
     * @var string
     */
    var $query_id = NULL;

    /**
     * Number of queries on this connection
     * @access public
     * @var integer
     */
    var $numqueries = NULL;

    /**
     * Handle voor filename
     * @access private
     * @var handle
     */
    var $handle = NULL;

    /**
     * Handle voor filename
     * @access private
     * @var handle
     */
    var $debug = 0;

    /**
     * String voor de filename
     * @access public
     * @var string
     */
    var $filename = NULL;

    /**
     * Functie om een database connectie te maken
     *
     * Omdat deze functie dezelfde naam als de class heeft,
     * wordt deze functie automatisch aangeroepen zodra een
     * nieuwe instantie van de class wordt gemaakt.
     * {@source }
     */
    function Connection ($host = "", $db   = "", $user = "", $pass = "") {
        $this->hostname = $host;
        $this->username = $user;
        $this->password = $pass;
        $this->database = $db;
        $this->link_id  = mysql_connect($this->hostname, $this->username, $this->password);
        if ($this->link_id===false) throw new Exception("Unable to connect to database server: " . mysql_error());
        $this->Usedb($this->database);
    }

    /**
     * Functie voor het selecteren van de database
     * {@source }
     */
    function Usedb ($db="test") {
        if (mysql_select_db($db)===false) throw new Exception("Unable to open database: " . mysql_error());
    }

    /**
     * Functie om laatste ID op te halen
     * {@source }
     */
    function GetID () {
        return mysql_insert_id();
    }

    /**
     * Functie voor het uitvoeren van een query
     * {@source }
     */
    function Query ($query) {
        if ($this->debug==1) {
            $this->handle = fopen($this->filename,"a+");
            fwrite($this->handle, $query."\n");
            fclose($this->handle);
        }

        $this->query_id = mysql_query($query,$this->link_id);
        if ($this->query_id===false) throw new Exception("Unable to execute query: " . mysql_error());
        $this->numqueries++;
    }

    /**
     * Functie voor het uitvoeren van een query met teruggave van ID
     * {@source }
     */
    function QueryReturn ($query) {
        $this->query_id = mysql_query($query,$this->link_id);
        if ($this->query_id===false) throw new Exception("Unable to execute query: " . mysql_error());
        return mysql_insert_id();
    }

    /**
     * Functie voor het ophalen van een rij uit een query
     * {@source }
     */
    function Fetch () {
        if ($this->query_id) {
            return mysql_fetch_assoc($this->query_id);
        } else {
            return FALSE;
        }
    }

    /**
     * Functie voor het sluiten van een database connectie
     * {@source }
     */
    function Close () {
        mysql_close($this->link_id);
    }

    /**
     * Functie voor het ophalen van het aantal rijen in een query
     * {@source }
     */
    function Numrows () {
        if ($this->query_id) {
            return mysql_num_rows($this->query_id);
        } else {
            return FALSE;
        }
    }

}
?>

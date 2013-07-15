<?php
namespace Aura\Sql\Pdo;

use PDO;
use PDOStatement;

/**
 * 
 * This extended version of PDO provides:
 * 
 * - Lazy connection. The instance connects to the database only on method
 *   calls that require a connection. This means you can create an instance
 *   and not incur the cost of a connection if you never make a query.
 * 
 * - Array quoting. The quote() method will accept an array as input, and
 *   return a string of comma-separated quoted values. In addition, named
 *   placeholders in prepared statements that are bound to array values will
 *   be replaced with comma-separated quoted values. This means you can bind
 *   an array of values to a placeholder used with an `IN (...)` condition.
 * 
 * - Bind values. You may provide values for binding to the next query using
 *   bindValues(). Mulitple calls to bindValues() will merge, not reset, the
 *   values. The values will be reset after calling query(), exec(),
 *   prepare(), or any of the fetch*() methods.
 * 
 * - Fetch methods. The class provides several fetch*() methods to reduce
 *   boilerplate code elsewhere. This means you can call, e.g., fetchAll()
 *   directly on the instance instead of having to prepare a statement, bind
 *   values, execute, and then fetch from the prepared statement. All of the
 *   fetch*() methods take an array of values to bind to to the query.
 * 
 * By defult, it starts in the ERRMODE_EXCEPTION instead of ERRMODE_SILENT.
 * 
 */
class ExtendedPdo extends PDO implements ExtendedPdoInterface
{
    protected $bind_values = [];
    
    protected $dsn;
    
    protected $username;
    
    protected $password;
    
    protected $options = [];
    
    protected $attributes = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => true,
    ];
    
    protected $connected = false;
    
    protected $profiler;
    
    protected $profile_info;
    
    /**
     * 
     * Constructor; retains connection information but does not make a
     * connection.
     * 
     * @param string $dsn The data source name for the connection.
     * 
     * @param string $username The username for the connection.
     * 
     * @param string $password The password for the connection.
     * 
     * @param array $options Driver-specific options.
     * 
     * @param array $attributes Attributes to set after connection.
     * 
     * @see http://php.net/manual/en/pdo.construct.php
     * 
     * @see connect()
     * 
     */
    public function __construct(
        $dsn,
        $username = null,
        $password = null,
        array $options = null,
        array $attributes = null
    ) {
        $this->dsn      = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->options  = $options;
        
        // can't use array_merge, as it will renumber keys
        foreach ((array) $attributes as $attribute => $value) {
            $this->attributes[$attribute] = $value;
        }
    }
    
    /**
     * 
     * Connects to the database and sets PDO attributes.
     * 
     * @return void
     * 
     * @throws PDOException if the connection fails.
     * 
     */
    public function connect()
    {
        // don't connect twice
        if ($this->connected) {
            return;
        }
        
        // connect to the database
        $this->beginProfile(__FUNCTION__);
        parent::__construct(
            $this->dsn,
            $this->username,
            $this->password,
            $this->options
        );
        $this->endProfile();
        
        // remember that we have connected
        $this->connected = true;
        
        // set attributes
        foreach ($this->attributes as $attribute => $value) {
            $this->setAttribute($attribute, $value);
        }
    }
    
    public function isConnected()
    {
        return $this->connected;
    }
    
    public function setAttribute($attribute, $value)
    {
        if ($this->connected) {
            return parent::setAttribute($attribute, $value);
        } else {
            $this->attributes[$attribute] = $value;
        }
    }
    
    public function getAttribute($attribute)
    {
        $this->connect();
        return parent::getAttribute($attribute);
    }
    
    public function errorCode()
    {
        $this->connect();
        return parent::errorCode();
    }
    
    public function errorInfo()
    {
        $this->connect();
        return parent::errorInfo();
    }
    
    /**
     * 
     * Retains several values to bind to the next query statement; these will
     * be merges with existing bound values, and will be reset after the
     * next query.
     * 
     * @param array $values An array where the key is the parameter name and
     * the value is the parameter value.
     * 
     * @return void
     * 
     */
    public function bindValues(array $bind_values)
    {
        $this->bind_values = array_merge($this->bind_values, $bind_values);
    }
    
    /**
     * 
     * Returns the array of values to bind to the next query.
     * 
     * @return array
     * 
     */
    public function getBindValues()
    {
        return $this->bind_values;
    }
    
    /**
     * 
     * Connects to the database and prepares an SQL statement to be executed,
     * using values that been bound for the next query.
     * 
     * This override only binds values that have placeholders in the
     * statement, thereby avoiding errors from PDO regarding too many bound
     * values.
     * 
     * If a placeholder value is an array, the array is converted to a string
     * of comma-separated quoted values; e.g., for an `IN (...)` condition.
     * The quoted string is replaced directly into the statement instead of
     * using `PDOStatement::bindValue()` proper.
     * 
     * @param string $statement The SQL statement to prepare for execution.
     * 
     * @param array $options Set these attributes on the returned
     * PDOStatement.
     * 
     * @return PDOStatement
     * 
     * @see http://php.net/manual/en/pdo.prepare.php
     * 
     */
    public function prepare($statement, $options = [])
    {
        $this->connect();
        
        // are there any bind values?
        if (! $this->bind_values) {
            return parent::prepare($statement, $options);
        }

        // a list of placeholders to bind at the end of this method
        $placeholders = array();

        // find all parts not inside quotes or backslashed-quotes
        $apos = "'";
        $quot = '"';
        $parts = preg_split(
            "/(($apos+|$quot+|\\$apos+|\\$quot+).*?)\\2/m",
            $statement,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        // loop through the non-quoted parts (0, 3, 6, 9, etc.)
        $k = count($parts);
        for ($i = 0; $i <= $k; $i += 3) {

            // get the part as a reference so it can be modified in place
            $part =& $parts[$i];

            // find all :placeholder matches in the part
            preg_match_all(
                "/\W:([a-zA-Z_][a-zA-Z0-9_]*)/m",
                $part . PHP_EOL,
                $matches
            );

            // for each of the :placeholder matches ...
            foreach ($matches[1] as $key) {
                // is the corresponding data element an array?
                $bind_array = isset($this->bind_values[$key])
                           && is_array($this->bind_values[$key]);
                if ($bind_array) {
                    // PDO won't bind an array; quote and replace directly
                    $find = "/(\W)(:$key)(\W)/m";
                    $repl = '${1}'
                          . $this->quote($this->bind_values[$key])
                          . '${3}';
                    $part = preg_replace($find, $repl, $part);
                } else {
                    // not an array, retain the placeholder name for later
                    $placeholders[] = $key;
                }
            }
        }

        // bring the parts back together in case they were modified
        $statement = implode('', $parts);

        // prepare the statement
        $sth = parent::prepare($statement, $options);

        // for the placeholders we found, bind the corresponding data values
        foreach ($placeholders as $key) {
            $sth->bindValue($key, $this->bind_values[$key]);
        }

        // clear out bind values, and done!
        return $sth;
    }
    
    /**
     * 
     * Connects to the database, prepares a statement using the bound values,
     * executes the statement, and returns the number of affected rows.
     * 
     * @param string $statement The SQL statement to prepare and execute.
     * 
     * @return void
     * 
     * @see http://php.net/manual/en/pdo.exec.php
     * 
     */
    public function exec($statement)
    {
        $sth = $this->prepare($statement);
        
        $this->beginProfile(__FUNCTION__);
        $sth->execute();
        $this->endProfile($sth);
        
        $this->bind_values = [];
        return $sth->rowCount();
    }
    
    /**
     * 
     * Connects to the database, prepares a statement using the bound values,
     * executes the statement, and returns a PDOStatement result set.
     * 
     * @param string $statement The SQL statement to prepare and execute.
     * 
     * @param int $fetch_mode The `PDO::FETCH_*` type to set on the returned
     * `PDOStatement::setFetchMode()`.
     * 
     * @param mixed $fetch_arg1 The first additional argument to send to
     * `PDOStatement::setFetchMode()`.
     * 
     * @param mixed $fetch_arg2 The second additional argument to send to
     * `PDOStatement::setFetchMode()`.
     * 
     * @return PDOStatement
     * 
     * @see http://php.net/manual/en/pdo.query.php
     */
    public function query(
        $statement,
        $fetch_mode = null,
        $fetch_arg1 = null,
        $fetch_arg2 = null
    ) {
        // prepare and execute
        $sth = $this->prepare($statement);
        $this->beginProfile(__FUNCTION__);
        $sth->execute();
        $this->endProfile($sth);
        
        // allow for optional fetch mode
        if ($fetch_arg2 !== null) {
            $sth->setFetchMode($fetch_mode, $fetch_arg1, $fetch_arg2);
        } elseif ($fetch_arg1 !== null) {
            $sth->setFetchMode($fetch_mode, $fetch_arg1);
        } elseif ($fetch_mode !== null) {
            $sth->setFetchMode($fetch_mode);
        }
        
        // done
        $this->bind_values = [];
        return $sth;
    }
    
    /**
     * 
     * Returns the last inserted autoincrement sequence value.
     * 
     * @param string $name The name of the sequence to check; typically needed
     * only for PostgreSQL, where it takes the form of `<table>_<column>_seq`.
     * 
     * @return int
     * 
     * @see http://php.net/manual/en/pdo.lastinsertid.php
     * 
     */
    public function lastInsertId($name = null)
    {
        $this->connect();
        $this->beginProfile(__FUNCTION__);
        $result = parent::lastInsertId($name);
        $this->endProfile();
        return $result;
    }
    
    /**
     * 
     * Fetches a sequential array of rows from the database; the rows
     * are represented as associative arrays.
     * 
     * @param string $statement The SQL statement to prepare and execute.
     * 
     * @param array $bind_values Values to bind to the query.
     * 
     * @return array
     * 
     */
    public function fetchAll($statement, array $bind_values = [])
    {
        $this->bindValues($bind_values);
        $sth = $this->query($statement);
        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 
     * Fetches an associative array of rows from the database; the rows
     * are represented as associative arrays. The array of rows is keyed
     * on the first column of each row.
     * 
     * N.b.: if multiple rows have the same first column value, the last
     * row with that value will override earlier rows.
     * 
     * @param string $statement The SQL statement to prepare and execute.
     * 
     * @param array $bind_values Values to bind to the query.
     * 
     * @return array
     * 
     */
    public function fetchAssoc($statement, array $bind_values = [])
    {
        $this->bindValues($bind_values);
        $sth = $this->query($statement);
        $data = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $key = current($row); // value of the first element
            $data[$key] = $row;
        }
        return $data;
    }

    /**
     * 
     * Fetches the first column of rows as a sequential array.
     * 
     * @param string $statement The SQL statement to prepare and execute.
     * 
     * @param array $bind_values Values to bind to the query.
     * 
     * @return array
     * 
     */
    public function fetchCol($statement, array $bind_values = [])
    {
        $this->bindValues($bind_values);
        $sth = $this->query($statement);
        return $sth->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /**
     * 
     * Fetches one row from the database as an associative array.
     * 
     * @param string $statement The SQL statement to prepare and execute.
     * 
     * @param array $bind_values Values to bind to the query.
     * 
     * @return array
     * 
     */
    public function fetchOne($statement, array $bind_values = [])
    {
        $this->bindValues($bind_values);
        $sth = $this->query($statement);
        return $sth->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 
     * Fetches an associative array of rows as key-value pairs (first 
     * column is the key, second column is the value).
     * 
     * @param string $statement The SQL statement to prepare and execute.
     * 
     * @param array $bind_values Values to bind to the query.
     * 
     * @return array
     * 
     */
    public function fetchPairs($statement, array $bind_values = [])
    {
        $this->bindValues($bind_values);
        $sth = $this->query($statement);
        return $sth->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * 
     * Fetches the very first value (i.e., first column of the first row).
     * 
     * @param string $statement The SQL statement to prepare and execute.
     * 
     * @param array $bind_values Values to bind to the query.
     * 
     * @return mixed
     * 
     */
    public function fetchValue($statement, array $bind_values = [])
    {
        $this->bindValues($bind_values);
        $sth = $this->query($statement);
        return $sth->fetchColumn(0);
    }

    /**
     * 
     * Connects to the database, begins a transaction, and turns off
     * autocommit mode.
     * 
     * @return bool True on success, false on failure.
     * 
     * @see http://php.net/manual/en/pdo.begintransaction.php
     * 
     */
    public function beginTransaction()
    {
        $this->connect();
        $this->beginProfile(__FUNCTION__);
        $result = parent::beginTransaction();
        $this->endProfile();
        return $result;
    }
    
    /**
     * 
     * Is a transaction currently active?
     * 
     * @return bool
     * 
     * @see http://php.net/manual/en/pdo.intransaction.php
     * 
     */
    public function inTransaction()
    {
        $this->connect();
        $this->beginProfile(__FUNCTION__);
        $result = parent::inTransaction();
        $this->endProfile();
        return $result;
    }
    
    /**
     * 
     * Connects to the database, commits the existing transaction, and
     * restores autocommit mode.
     * 
     * @return bool True on success, false on failure.
     * 
     * @see http://php.net/manual/en/pdo.commit.php
     * 
     */
    public function commit()
    {
        $this->connect();
        $this->beginProfile(__FUNCTION__);
        $result = parent::commit();
        $this->endProfile();
        return $result;
    }
    
    /**
     * 
     * Connects to the database, rolls back the current transaction, and
     * restores autocommit mode.
     * 
     * @return bool True on success, false on failure.
     * 
     * @see http://php.net/manual/en/pdo.rollback.php
     * 
     */
    public function rollBack()
    {
        $this->connect();
        $this->beginProfile(__FUNCTION__);
        $result = parent::rollBack();
        $this->endProfile();
    }
    
    /**
     * 
     * Quotes a value for use in an SQL statement.
     * 
     * This differs from `PDO::quote()` in that it will convert an array into
     * a string of comma-separated quoted values.
     * 
     * @param mixed $value The value to quote.
     * 
     * @param int $parameter_type A data type hint for the database driver.
     * 
     * @return mixed The quoted value.
     * 
     * @see http://php.net/manual/en/pdo.quote.php
     * 
     */
    public function quote($value, $parameter_type = PDO::PARAM_STR)
    {
        $this->connect();
        
        // quote array values, not keys, then combine with commas. do not
        // recurse into sub-arrays.
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = parent::quote($v, $parameter_type);
            }
            return implode(', ', $value);
        }
        
        // normal quoting
        return parent::quote($value, $parameter_type);
    }
    
    public function setProfiler(ProfilerInterface $profiler)
    {
        $this->profiler = $profiler;
    }

    public function getProfiler()
    {
        return $this->profiler;
    }
    
    protected function beginProfile($function)
    {
        // if there's no profiler, can't profile
        if (! $this->profiler) {
            return;
        }
        
        // retain starting profile info
        $this->profile_info['time'] = microtime(true);
        $this->profile_info['function'] = $function;
        $this->profile_info['bind_values'] = $this->bind_values;
    }
    
    protected function endProfile(PDOStatement $sth = null)
    {
        // if there's no profiler, can't profile
        if (! $this->profiler) {
            return;
        }
        
        // add an entry to the profiler
        $this->profiler->addProfile(
            microtime(true) - $this->profile_info['time'],
            $this->profile_info['function'],
            $sth ? $sth->queryString : null,
            $this->profile_info['bind_values']
        );
        
        // clear the starting profile info
        $this->profile_info = [];
    }
    
}

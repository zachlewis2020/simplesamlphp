<?php


/**
 * A class for cryptography-related functions
 *
 * @package SimpleSAMLphp
 */
class SimpleSAML_Utils_Crypto
{

    /**
     * Decrypt data using AES and the system-wide secret salt as key.
     *
     * @param string $data The encrypted data to decrypt.
     *
     * @return string The decrypted data.
     * @throws SimpleSAML_Error_Exception If the mcrypt module is not loaded or $ciphertext is not a string.
     * @author Andreas Solberg, UNINETT AS <andreas.solberg@uninett.no>
     * @author Jaime Perez, UNINETT AS <jaime.perez@uninett.no>
     */
    public static function aesDecrypt($ciphertext)
    {
        if (!is_string($ciphertext)) {
            throw new SimpleSAML_Error_Exception('Input parameter "$ciphertext" must be a string.');
        }
        if (!function_exists("mcrypt_encrypt")) {
            throw new SimpleSAML_Error_Exception("The mcrypt PHP module is not loaded.");
        }

        $enc = MCRYPT_RIJNDAEL_256;
        $mode = MCRYPT_MODE_CBC;

        $ivSize = mcrypt_get_iv_size($enc, $mode);
        $keySize = mcrypt_get_key_size($enc, $mode);

        $key = hash('sha256', SimpleSAML_Utilities::getSecretSalt(), true);
        $key = substr($key, 0, $keySize);

        $iv = substr($ciphertext, 0, $ivSize);
        $data = substr($ciphertext, $ivSize);

        $clear = mcrypt_decrypt($enc, $key, $data, $mode, $iv);

        $len = strlen($clear);
        $numpad = ord($clear[$len - 1]);
        $clear = substr($clear, 0, $len - $numpad);

        return $clear;
    }

    /**
     * Encrypt data using AES and the system-wide secret salt as key.
     *
     * @param string $data The data to encrypt.
     *
     * @return string The encrypted data and IV.
     * @throws SimpleSAML_Error_Exception If the mcrypt module is not loaded or $data is not a string.
     * @author Andreas Solberg, UNINETT AS <andreas.solberg@uninett.no>
     * @author Jaime Perez, UNINETT AS <jaime.perez@uninett.no>
     */
    public static function aesEncrypt($data)
    {
        if (!is_string($data)) {
            throw new SimpleSAML_Error_Exception('Input parameter "$data" must be a string.');
        }
        if (!function_exists("mcrypt_encrypt")) {
            throw new SimpleSAML_Error_Exception('The mcrypt PHP module is not loaded.');
        }

        $enc = MCRYPT_RIJNDAEL_256;
        $mode = MCRYPT_MODE_CBC;

        $blockSize = mcrypt_get_block_size($enc, $mode);
        $ivSize = mcrypt_get_iv_size($enc, $mode);
        $keySize = mcrypt_get_key_size($enc, $mode);

        $key = hash('sha256', SimpleSAML_Utilities::getSecretSalt(), true);
        $key = substr($key, 0, $keySize);

        $len = strlen($data);
        $numpad = $blockSize - ($len % $blockSize);
        $data = str_pad($data, $len + $numpad, chr($numpad));

        $iv = openssl_random_pseudo_bytes($ivSize);

        $data = mcrypt_encrypt($enc, $key, $data, $mode, $iv);

        return $iv.$data;
    }


    /**
     * Load a private key from metadata.
     *
     * This function loads a private key from a metadata array. It looks for the following elements:
     * - 'privatekey': Name of a private key file in the cert-directory.
     * - 'privatekey_pass': Password for the private key.
     *
     * It returns and array with the following elements:
     * - 'PEM': Data for the private key, in PEM-format.
     * - 'password': Password for the private key.
     *
     * @param SimpleSAML_Configuration $metadata The metadata array the private key should be loaded from.
     * @param bool                     $required Whether the private key is required. If this is true, a
     * missing key will cause an exception. Defaults to false.
     * @param string                   $prefix The prefix which should be used when reading from the metadata
     * array. Defaults to ''.
     *
     * @return array|NULL Extracted private key, or NULL if no private key is present.
     * @throws SimpleSAML_Error_Exception If no private key is found in the metadata, or it was not possible to load it.
     *
     * @author Andreas Solberg, UNINETT AS <andreas.solberg@uninett.no>
     * @author Olav Morken, UNINETT AS <olav.morken@uninett.no>
     */
    public static function loadPrivateKey(SimpleSAML_Configuration $metadata, $required = false, $prefix = '')
    {
        if (!is_bool($required) || !is_string($prefix)) {
            throw new SimpleSAML_Error_Exception('Invalid input parameters.');
        }

        $file = $metadata->getString($prefix.'privatekey', null);
        if ($file === null) {
            // no private key found
            if ($required) {
                throw new SimpleSAML_Error_Exception('No private key found in metadata.');
            } else {
                return null;
            }
        }

        $file = SimpleSAML_Utilities::resolveCert($file);
        $data = @file_get_contents($file);
        if ($data === false) {
            throw new SimpleSAML_Error_Exception('Unable to load private key from file "'.$file.'"');
        }

        $ret = array(
            'PEM' => $data,
        );

        if ($metadata->hasValue($prefix.'privatekey_pass')) {
            $ret['password'] = $metadata->getString($prefix.'privatekey_pass');
        }

        return $ret;
    }

    /**
     * Get public key or certificate from metadata.
     *
     * This function implements a function to retrieve the public key or certificate from a metadata array.
     *
     * It will search for the following elements in the metadata:
     * - 'certData': The certificate as a base64-encoded string.
     * - 'certificate': A file with a certificate or public key in PEM-format.
     * - 'certFingerprint': The fingerprint of the certificate. Can be a single fingerprint, or an array of multiple
     * valid fingerprints.
     *
     * This function will return an array with these elements:
     * - 'PEM': The public key/certificate in PEM-encoding.
     * - 'certData': The certificate data, base64 encoded, on a single line. (Only present if this is a certificate.)
     * - 'certFingerprint': Array of valid certificate fingerprints. (Only present if this is a certificate.)
     *
     * @param SimpleSAML_Configuration $metadata The metadata.
     * @param bool                     $required Whether the private key is required. If this is TRUE, a missing key
     *     will cause an exception. Default is FALSE.
     * @param string                   $prefix The prefix which should be used when reading from the metadata array.
     *     Defaults to ''.
     *
     * @return array|NULL Public key or certificate data, or NULL if no public key or certificate was found.
     *
     * @throws SimpleSAML_Error_Exception If no private key is found in the metadata, or it was not possible to load it.
     * @author Andreas Solberg, UNINETT AS <andreas.solberg@uninett.no>
     * @author Olav Morken, UNINETT AS <olav.morken@uninett.no>
     * @author Lasse Birnbaum Jensen
     */
    public static function loadPublicKey(SimpleSAML_Configuration $metadata, $required = false, $prefix = '')
    {
        assert('is_bool($required)');
        assert('is_string($prefix)');

        $keys = $metadata->getPublicKeys(null, false, $prefix);
        if ($keys !== null) {
            foreach ($keys as $key) {
                if ($key['type'] !== 'X509Certificate') {
                    continue;
                }
                if ($key['signing'] !== true) {
                    continue;
                }
                $certData = $key['X509Certificate'];
                $pem = "-----BEGIN CERTIFICATE-----\n".
                    chunk_split($certData, 64).
                    "-----END CERTIFICATE-----\n";
                $certFingerprint = strtolower(sha1(base64_decode($certData)));

                return array(
                    'certData'        => $certData,
                    'PEM'             => $pem,
                    'certFingerprint' => array($certFingerprint),
                );
            }
            // no valid key found
        } elseif ($metadata->hasValue($prefix.'certFingerprint')) {
            // we only have a fingerprint available
            $fps = $metadata->getArrayizeString($prefix.'certFingerprint');

            // normalize fingerprint(s) - lowercase and no colons
            foreach ($fps as &$fp) {
                assert('is_string($fp)');
                $fp = strtolower(str_replace(':', '', $fp));
            }

            // We can't build a full certificate from a fingerprint, and may as well return an array with only the
            //fingerprint(s) immediately.
            return array('certFingerprint' => $fps);
        }

        // no public key/certificate available
        if ($required) {
            throw new SimpleSAML_Error_Exception('No public key / certificate found in metadata.');
        } else {
            return null;
        }
    }

    /**
     * This function hashes a password with a given algorithm.
     *
     * @param string $password The password to hash.
     * @param string $algorithm The hashing algorithm, uppercase, optionally prepended with 'S' (salted). See
     *     hash_algos() for a complete list of hashing algorithms.
     * @param string $salt An optional salt to use.
     *
     * @return string The hashed password.
     * @throws SimpleSAML_Error_Exception If the algorithm specified is not supported, or the input parameters are not
     *     strings.
     * @see hash_algos()
     * @author Dyonisius Visser, TERENA <visser@terena.org>
     * @author Jaime Perez, UNINETT AS <jaime.perez@uninett.no>
     */
    public static function pwHash($password, $algorithm, $salt = null)
    {
        if (!is_string($algorithm) || !is_string($password)) {
            throw new SimpleSAML_Error_Exception('Invalid input parameters.');
        }

        // hash w/o salt
        if (in_array(strtolower($algorithm), hash_algos())) {
            $alg_str = '{'.str_replace('SHA1', 'SHA', $algorithm).'}'; // LDAP compatibility
            $hash = hash(strtolower($algorithm), $password, true);
            return $alg_str.base64_encode($hash);
        }

        // hash w/ salt
        if (!$salt) { // no salt provided, generate one
            // default 8 byte salt, but 4 byte for LDAP SHA1 hashes
            $bytes = ($algorithm == 'SSHA1') ? 4 : 8;
            $salt = openssl_random_pseudo_bytes($bytes);
        }

        if ($algorithm[0] == 'S' && in_array(substr(strtolower($algorithm), 1), hash_algos())) {
            $alg = substr(strtolower($algorithm), 1); // 'sha256' etc
            $alg_str = '{'.str_replace('SSHA1', 'SSHA', $algorithm).'}'; // LDAP compatibility
            $hash = hash($alg, $password.$salt, true);
            return $alg_str.base64_encode($hash.$salt);
        }

        throw new SimpleSAML_Error_Exception('Hashing algorithm \''.strtolower($algorithm).'\' is not supported');
    }


    /**
     * This function checks if a password is valid
     *
     * @param string $hash The password as it appears in password file, optionally prepended with algorithm.
     * @param string $password The password to check in clear.
     *
     * @return boolean True if the hash corresponds with the given password, false otherwise.
     * @throws SimpleSAML_Error_Exception If the algorithm specified is not supported, or the input parameters are not
     *     strings.
     * @author Dyonisius Visser, TERENA <visser@terena.org>
     */
    public static function pwValid($hash, $password)
    {
        if (!is_string($hash) || !is_string($password)) {
            throw new SimpleSAML_Error_Exception('Invalid input parameters.');
        }

        // match algorithm string (e.g. '{SSHA256}', '{MD5}')
        if (preg_match('/^{(.*?)}(.*)$/', $hash, $matches)) {

            // LDAP compatibility
            $alg = preg_replace('/^(S?SHA)$/', '${1}1', $matches[1]);

            // hash w/o salt
            if (in_array(strtolower($alg), hash_algos())) {
                return $hash === self::pwHash($password, $alg);
            }

            // hash w/ salt
            if ($alg[0] === 'S' && in_array(substr(strtolower($alg), 1), hash_algos())) {
                $php_alg = substr(strtolower($alg), 1);

                // get hash length of this algorithm to learn how long the salt is
                $hash_length = strlen(hash($php_alg, '', true));
                $salt = substr(base64_decode($matches[2]), $hash_length);
                return ($hash === self::pwHash($password, $alg, $salt));
            }
        } else {
            return $hash === $password;
        }

        throw new SimpleSAML_Error_Exception('Hashing algorithm \''.strtolower($alg).'\' is not supported');
    }
}

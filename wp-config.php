<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/documentation/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'admontec_db' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '4mPQPK;Qu1?v2.0[+VSVcBa:K:)!Jnrr05e/LF.O(&kV)D].WT{Xmw!:Ud`=0^JK' );
define( 'SECURE_AUTH_KEY',  'X{|eu>lYcrqY!y,S#=^usmRS)nw0LYdE#U)b$67u{&x) ^c_jDL=I^+A$9.Fom%M' );
define( 'LOGGED_IN_KEY',    '`BFggSY|^kc[.]xE#?x`t>MS%[c_sPMaSVCv#Op{xKHaQS/mEb}Jc?U!n]6Dxb52' );
define( 'NONCE_KEY',        'K`X<Kewm8x}qBe.xIpQwWxWcn:(_R8U)Riu39m|!F|BBGe_o~Hu<k9|k-m;-/P>B' );
define( 'AUTH_SALT',        '{jqe3lRFwmk~=MF^ K;( l@*31x+ln`+7G@QaG{q<=_<NK+R?XP+7BIC.mH0uky{' );
define( 'SECURE_AUTH_SALT', '8] jy&?s}r,#|t_u+|c]zXt* p<R4O8Ppu6L!Q9SWa&J`^d7mBJ<[98#y]U,kvr,' );
define( 'LOGGED_IN_SALT',   '8/~Dvie%xefF5;}7o*l SMPb~9?q)2A!!w9UOuDy^DQM2/kmr|w%=E[hP9E8A#n ' );
define( 'NONCE_SALT',       ']zF^/lEx>O0gH]ckd|LgD~! 0JZzWsnH[}CP~Z;QG,=P?D*j%]s11HI>i6J60J7q' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/documentation/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';

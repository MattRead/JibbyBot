#!/usr/bin/env php
<?php

	if ( isset( $argv[1] ) && $argv[1] == 'stop' ) {
		$pid = file_get_contents('phergie.pid');
		posix_kill( $pid, 9 );
		sleep(1);
		unlink( 'phergie.pid' );	// delete the temp PID file when we're done
		exit(1);
	}
	
	if ( isset( $argv[1] ) && $argv[1] == 'start' && isset( $argv[2] ) ) {
		$settings_file = $argv[2];
	}
	else {
		$settings_file = 'Settings.php';
	}

	$pid = pcntl_fork();

	if ( $pid == -1 ) {
		die('could not fork!');
	}
	else if ( $pid ) {
		// we are the parent
		file_put_contents('phergie.pid', $pid);
		exit(0);
		//pcntl_wait( $status );	// protect against zombie children
	}
	else {
		// we are the child
	}

	if ( !posix_setsid() ) {
		die('Could not detach from terminal');
	}

	fclose(STDOUT);
	fclose(STDIN);
	fclose(STDERR);

//	ob_start();
	exec( dirname( __FILE__ ) . '/phergie/phergie.php ' . $settings_file );
//	ob_end_clean();

?>
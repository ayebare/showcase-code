<?php
$messages_response = array(
	"messages"      => array(
		array(
			"id"       => "1818f71c2f5cbf0c",
			"threadId" => "1818f71c2f5cbf0c"
		),
		array(
			"id"       => "1818f6e43b70a3ac",
			"threadId" => "1818f6e43b70a3ac"
		),
		array(
			"id"       => "1818f69b324c90a5",
			"threadId" => "1818f69b324c90a5"
		),
	),
	"nextPageToken" => "06568563630357605399",
);


$batch_response =
	array(
		array(
			"id"       => "1818f71c2f5cbf0c",
			"threadId" => "1818f71c2f5cbf0c",
			"labelIds" => array(
				"INBOX"
			),
			"snippet"  => "Sample Snippet",
			"payload"  => array(
				"partId"   => "",
				"mimeType" => "multipart/alternative",
				"filename" => "",
				"headers"  => array(
					array(
						"name"  => "Delivered-To",
						"value" => "tester@test.com"
					),
					array(
						"name"  => "Date",
						"value" => "Thu, 23 Jun 2022 07:23:25 GMT"
					),
					array(
						"name"  => "Subject",
						"value" => "Tests"
					),
					array(
						"name"  => "From",
						"value" => "Test test@accounts.tests.com"
					),
					array(
						"name"  => "To",
						"value" => "tester@test.com"
					),
					array(
						"name"  => "Content-Type",
						"value" => "multipart/alternative; boundary=\"0000000000005ad99c05e2185465\""
					)
				),
				"parts"    => array(
					array(
						"mimeType" => "text/plain",
						"body"     => array(
							"data" => "text plain data"
						)
					),
					array(
						"mimeType" => "text/html",
						"body"     => array(
							"data" => "text html data"
						)
					)
				)
			),
		)
	);

return array(
	'fetch_messages'       => json_decode( json_encode( $messages_response ) ),
	'fetch_batch_messages' => json_decode( json_encode( ( $batch_response ) ) )
);

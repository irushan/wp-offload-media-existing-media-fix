<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once("wp-load.php");

global $wpdb;
global $table_prefix; // $table_prefix

if (!isset($as3cf)) die('<p><strong>WP Offload Media Lite</strong> plugin is not available on this site. Please check and run again.</p>');

$itemsTable = 'as3cf_items';
$lastAttachmentID = get_option('_site_transient_wh_last_sync_attachment', 0);
$offloadSettings = get_option('tantan_wordpress_s3');
$region = $offloadSettings['region'];
$bucket = $offloadSettings['bucket'];
$protocol = ($offloadSettings['force-https']) ? 'https://' : 'http://';
$deliveryDomain = ($offloadSettings['enable-delivery-domain']) ? $protocol . $offloadSettings['delivery-domain'] : '';
$objectPrefix = rtrim($offloadSettings['object-prefix'], '/');
$uploadFolder = wp_upload_dir();
$uploadFolderURL = $uploadFolder['baseurl'];

if (empty($deliveryDomain)) {
	$deliveryDomain = "https://$bucket.s3.$region.amazonaws.com/";
}

function updatePostContent($type, $table, $blog, $s3bucket, $reverse = false) {
    // $reverse is to remove the post_content updates and put them back to serving locally
    $from = (!$reverse) ? $blog : $s3bucket;
    $to = (!$reverse) ? $s3bucket : $blog;

    return "UPDATE $table SET post_content = replace(post_content, '$type=\"$from', '$type=\"$to');";
}

if (isset($_GET['remove']) && $_GET['remove'] == 'true') { // ?remove=true

	echo '<h1>WP Offload S3 - Amazon S3 Media to Local Server (Remove)</h1>';

    $wpdb->query("TRUNCATE TABLE {$table_prefix}{$itemsTable}");

	$removeAmazonS3Info = "DELETE FROM " . $table_prefix . "postmeta WHERE meta_key = 'as3cf_filesize_total';";
	$reversePostContentHref = updatePostContent('href', $table_prefix . 'posts', $uploadFolderURL, $deliveryDomain . '/' . $objectPrefix, true);
	$reversePostContentSrc = updatePostContent('src', $table_prefix . 'posts', $uploadFolderURL, $deliveryDomain . '/' . $objectPrefix, true);

	echo '<p><strong>RUNNING COMMAND:</strong> ' . $removeAmazonS3Info . ' - ';
	if ($deleteInfo = $wpdb->query($removeAmazonS3Info)) {
		echo ' <strong>DONE, ' . $deleteInfo . ' rows affected</strong>';
	}
	echo '</p>';

	echo '<p><strong>RUNNING COMMAND:</strong> ' . $reversePostContentHref . ' - ';
	if ($postContentHref = $wpdb->query($reversePostContentHref)) {
		echo ' <strong>DONE, ' . $postContentHref . ' rows affected</strong>';
	}
	echo '</p>';

	echo '<p><strong>RUNNING COMMAND:</strong> ' . $reversePostContentSrc . ' - ';
	if ($postContentSrc = $wpdb->query($reversePostContentSrc)) {
		echo ' <strong>DONE, ' . $postContentSrc . ' rows affected</strong>';
	}
	echo '</p>';

	update_option('_site_transient_wh_last_sync_attachment', 0, false);

    exit();

}

echo '<h1>WP Offload S3 - Existing Media to Amazon S3</h1>';

#Getting the list of attachments

$sql = $wpdb->prepare("SELECT 
	p.ID,
	MAX(CASE WHEN pm.meta_key = '_wp_attached_file' THEN pm.meta_value END ) as attached_file,
	MAX(CASE WHEN pm.meta_key = '_wp_attachment_metadata' THEN pm.meta_value END ) as attachment_metadata
	FROM {$table_prefix}posts AS p
	LEFT JOIN {$table_prefix}postmeta AS pm ON p.ID = pm.post_id
	WHERE p.post_type = %s AND p.ID > %d
	GROUP BY p.ID",
'attachment', $lastAttachmentID);

$attachments = $wpdb->get_results($sql);

if (empty($attachments)) {
	die('<p>All the images are synced into WP Offload Media Lite table.</p>');
}

$format = [
	'provider' => '%s',
	'region' => '%s',
	'bucket' => '%s',
	'path' => '%s',
	'original_path' => '%s',
	'is_private' => '%d',
	'source_type' => '%s',
	'source_id' => '%d',
	'source_path' => '%s',
	'original_source_path' => '%s',
	'extra_info' => '%s',
	'originator' => '%d',
	'is_verified' => '%d',
];

$format = array_values($format);

foreach ($attachments as $attachment) {
	$lastAttachmentID = $attachment->ID;
	$attachmentMetadata = maybe_unserialize($attachment->attachment_metadata);

	$file = (isset($attachmentMetadata['file'])) ? explode('/', $attachmentMetadata['file']) : $attachment->attached_file;
	$extra_info = array(
		'__as3cf_primary' => array(
			'source_file' => (is_array($file)) ? $file[(count($file) - 1)] : $file,
			'is_private' => 0
		)
	);
	$totalSize = (int) (isset($attachmentMetadata['filesize'])) ? $attachmentMetadata['filesize'] : 0;
	if (isset($attachmentMetadata['sizes']) && is_array($attachmentMetadata['sizes'])) {
		foreach ($attachmentMetadata['sizes'] as $size => $item) {
			$extra_info[$size] = array(
				'source_file' => $item['file'],
				'is_private' => 0
			);
			$totalSize += (isset($item['filesize'])) ? $item['filesize'] : 0;
		}
	}
	
	$data = [
		'provider' => 'aws',
		'region' => $region,
		'bucket' => $bucket,
		'path' => $objectPrefix . '/' . $attachment->attached_file,
		'original_path' => $objectPrefix . '/' . $attachment->attached_file,
		'is_private' => 0,
		'source_type' => 'media-library',
		'source_id' => $attachment->ID,
		'source_path' => $attachment->attached_file,
		'original_source_path' => $attachment->attached_file,
		'extra_info' => serialize(array(
			'objects' => $extra_info,
			'private_prefix' => ''
		)),
		'originator' => 0,
		'is_verified' => 1,
	];

	#Adding record into WP Offload Media's table
	$wpdb->insert("{$table_prefix}{$itemsTable}", $data, $format);

	$updateResult = $wpdb->update("{$table_prefix}postmeta", array('meta_value' => $totalSize), array('post_id' => $attachment->ID, 'meta_key' => 'as3cf_filesize_total'));
	
	if ($updateResult === FALSE || $updateResult < 1) {
		$wpdb->insert("{$table_prefix}postmeta", array('post_id' => $attachment->ID, 'meta_key' => 'as3cf_filesize_total', 'meta_value' => $totalSize));
	}
}

$hrefMySQLUpdate = updatePostContent('href', $table_prefix . 'posts', $uploadFolderURL, $deliveryDomain . '/' . $objectPrefix);
$srcMySQLUpdate = updatePostContent('src', $table_prefix . 'posts', $uploadFolderURL, $deliveryDomain . '/' . $objectPrefix);

echo '<p><strong>RUNNING COMMAND:</strong> ' . $hrefMySQLUpdate . ' - ';
if ($postContentHref = $wpdb->query($hrefMySQLUpdate)) {
	echo ' <strong>DONE, ' . $postContentHref . ' rows affected</strong><br />';
}
echo '</p>';

echo '<p><strong>RUNNING COMMAND:</strong> ' . $srcMySQLUpdate . ' - ';
if ($postContentSrc = $wpdb->query($srcMySQLUpdate)) {
	echo ' <strong>DONE, ' . $postContentSrc . ' rows affected</strong><br />';
}
echo '</p>';

echo '<h3><strong>' . count($attachments) . '</strong> Media Files Updated!</h3>';

update_option('_site_transient_wh_last_sync_attachment', $lastAttachmentID, false);
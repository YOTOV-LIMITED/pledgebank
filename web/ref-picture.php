<?php
/*
 * ref-picture.php:
 * Alter picture on a pledge.
 * 
 * Copyright (c) 2005 UK Citizens Online Democracy. All rights reserved.
 * Email: chris@mysociety.org; WWW: http://www.mysociety.org/
 *
 * $Id: ref-picture.php,v 1.33 2007-10-12 13:12:48 matthew Exp $
 * 
 */

require_once '../phplib/pb.php';
require_once '../commonlib/phplib/db.php';

require_once '../phplib/page.php';
require_once '../phplib/pbperson.php';
require_once '../phplib/pledge.php';

require_once '../commonlib/phplib/importparams.php';

$picture_size_limit = 1000; // kilobytes
$picture_dimension_limit = microsite_picture_dimension_limit(); // pixels, width and height limit

$err = importparams(
            array('ref',   '/./',   '')
        );
if (!is_null($err))
    err(_("Missing pledge reference"));

page_check_ref(get_http_var('ref'));
$pledge = new Pledge($q_ref);
microsites_redirect($pledge);

$P = pb_person_if_signed_on();
if (!$P) {
    if ($pledge->has_picture()) {
        $reason_clause = "change the pledge's picture";
        $reason_clause_you = "change your pledge's picture";
        $P = pb_person_signon(array(
                    "reason_web" => _("Before you can change the pledge's picture, we need to check that you created the pledge."),
                    "reason_email" => _("Then you will be able to change your pledge's picture."),
                    "reason_email_subject" => _("Change your pledge's picture at PledgeBank.com"))
                );
    } else {
        $P = pb_person_signon(array(
                    "reason_web" => _("Before you can add a picture to the pledge, we need to check that you created the pledge."),
                    "reason_email" => _("Then you will be able to add a picture to your pledge."),
                    "reason_email_subject" => _('Add a picture to your pledge at PledgeBank.com'))
                );
    }
}

if ($P->id() != $pledge->creator_id()) {
    page_header("Add picture to pledge", array('ref'=>$pledge->ref(), 'pref' => $pledge->url_typein()) );
    print _("You must be the pledge creator to add a picture to a pledge.  Please
        <a href=\"/logout\">log out</a> and log in again as them.");
    page_footer();
    exit;
}

page_header($pledge->has_picture() ? _("Change pledge picture") : _("Add picture to pledge"),
    array('ref'=>$pledge->ref(), 'pref' => $pledge->url_typein()));

$picture_upload_allowed = is_null($pledge->pin());

// Upload picture
function upload_picture() {
    global $picture_upload_allowed, $picture_size_limit, $picture_dimension_limit, $pledge;
    $picture_url = "";
    $confirm_msg = "";
    if (get_http_var('removepicture')) {
        db_query("update pledges set picture = null where ref = ?",
            array($pledge->ref()));
        db_commit();
        print _("The picture has been removed.  Below you can see what the pledge now looks like.");
        $pledge = new Pledge($pledge->ref());
        return true;
    }

    if (microsite_preloaded_images(0) && $preloaded_id = get_http_var('preloaded_image')) {
        preg_match('/\.(\w+)$/', $preloaded_id, $matches); 
        $ext = $matches[1]; # loads $ext with the file extension (assume internal files have sensible extensions) 
        # check file exists -- basic validation
        if (! file_exists(OPTION_PB_PRELOADED_IMAGES_DIR . $preloaded_id)){
            return "There was an internal error: couldn't find $preloaded_id";
        }
        $picture_url = microsite_preloaded_image_url($preloaded_id);
        $confirm_msg = _("Thanks for selecting a picture for the pledge.  You can see below what it now looks like.");
    } else {
        
        if (!array_key_exists('userfile', $_FILES))
            return false;

        if (!$picture_upload_allowed) {
            return _("Picture not available for private pledge");
        }
    
        $tmp_name = $_FILES['userfile']['tmp_name'];
        if ($_FILES['userfile']['error'] > 0) {
            $errors = array(
                UPLOAD_ERR_INI_SIZE => _("There was an internal error uploading the picture.  The uploaded file exceeds the upload_max_filesize directive in php.ini"),
                UPLOAD_ERR_FORM_SIZE => sprintf(_("Please use a smaller picture.  Try scaling it down in a paint program, reducing the number of colours, or saving it as a JPEG or PNG.  Files of up to %d kilobytes are allowed."), $picture_size_limit),
                UPLOAD_ERR_PARTIAL => _("The uploaded file was only partially uploaded, please try again."),
                UPLOAD_ERR_NO_FILE => _("No file was uploaded, please try again."),
                UPLOAD_ERR_NO_TMP_DIR => _("There was an internal error uploading the picture.  Missing a temporary folder.")
            );
            return $errors[$_FILES['userfile']['error']];
        }
        if (!is_uploaded_file($tmp_name))
            return _("Failed to upload the picture, please try again.");

        if ($_FILES['userfile']['size'] > $picture_size_limit * 1024)
            return sprintf(_("Please use a smaller picture.  Try scaling it down in a paint program, reducing the number of colours, or saving it as a JPEG or PNG.  Files of up to %d kilobytes are allowed. Your picture is about %d kilobytes in size."), $picture_size_limit, intval($_FILES['userfile']['size'] / 1024) );
        elseif ($_FILES['userfile']['size'] == 0)
            /* This can occur when the user names a nonexistent file in their
             * browser. exif_imagetype barfs (fatal error) on an empty file, so
             * try to detect it here. */
            return _("We didn't receive a complete picture file.  Please check that you're uploading the picture you want to use.");
        elseif ($_FILES['userfile']['size'] < 64)
            /* Probably exif_imagetype can't cope with truncated files either. Why
             * take the chance? */
            return sprintf(_("That doesn't seem to be a valid picture file.  It is only %.2f kilobytes in size."), $_FILES['userfile']['size'] / 1024.);

        // TODO: Add BMP, and convert them to PNG.

        $picture_type = exif_imagetype($tmp_name);
        if ($picture_type == IMAGETYPE_GIF) {
            $ext = "gif";
        } elseif ($picture_type == IMAGETYPE_JPEG) {
            $ext = "jpeg";
        } elseif ($picture_type == IMAGETYPE_PNG) {
            $ext = "png";
        } else {
            return _("Please upload pictures of type GIF, JPEG or PNG.  You can use a paint program to convert them before uploading.");
        }

        list($width, $height) = getimagesize($tmp_name);
        if ($width > $picture_dimension_limit
           || $height > $picture_dimension_limit) {
           // Calculate new sizes
           $fraction = floatval($picture_dimension_limit) / floatval(max($width, $height));
           $newwidth = $width * $fraction;
           $newheight = $height * $fraction;
           // Resize image
           $dest = imagecreatetruecolor($newwidth, $newheight);
           if ($picture_type == IMAGETYPE_GIF) {
                $source = imagecreatefromgif($tmp_name);
           } elseif ($picture_type == IMAGETYPE_JPEG) {
                $source = imagecreatefromjpeg($tmp_name);
           } elseif ($picture_type == IMAGETYPE_PNG) {
                $source = imagecreatefrompng($tmp_name);
           }
           imagecopyresized($dest, $source, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
           imagejpeg($dest, $tmp_name);
           $ext = "jpeg";
        } 
    
        $base_name =  $pledge->ref() . "." . $ext;
        $picture_contents = file_get_contents($tmp_name);
        if (!$picture_contents)
            err("Failed to read file into memory");

        db_query("delete from picture where filename = ?", $base_name);
        db_query_literal("
            insert into picture (filename, data)
            values ('$base_name', '" . pg_escape_bytea($picture_contents) . "')");
        $picture_url = OPTION_BASE_URL . "/pics/$base_name";
        $confirm_msg = _("Thanks for uploading your picture to the pledge.  You can see below what it now looks like.");
    }
    db_query("
        update pledges
        set picture = ?,
            changetime = ms_current_timestamp()
        where ref = ?",
        $picture_url, $pledge->ref());
    db_commit();
    print $confirm_msg;
    $pledge = new Pledge($pledge->ref());
    return true;
}

$error = upload_picture();
if (gettype($error) == "string") {
    print "<div id=\"errors\"><ul><li>$error</li></ul></div>";
}

// Display admin page
$pledge->render_box(array('showdetails' => true));

if ($picture_upload_allowed) {
?>
    <form enctype="multipart/form-data" action="/<?=$q_h_ref?>/picture" method="POST">
<?  if ($pledge->has_picture()) {
        print h2(_('Change pledge picture'));
    } else {
        print h2(_('Add a picture to your pledge'));
    } ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?=$picture_size_limit*1024?>">
    <?  print p(microsite_picture_upload_advice()); ?>
    <?= microsite_picture_extra_form() ?>
    <p><input name="userfile" type="file"><input type="submit" value="<?=_('Submit') ?>">
<?  if ($pledge->has_picture()) {
        printf(p(_('Or you can %s if you don\'t want any image on your pledge any more.')), '<input name="removepicture" type="submit" value="' . _('Remove the picture') . '">');
    }
    print '</form>';
} else {
    print p(_("Pictures are currently not available for private pledges.  Please let us know if this is a problem."));
}

page_footer();

?>

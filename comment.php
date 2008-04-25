<?php 
// comment.php -- HotCRP paper comment display/edit page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/papertable.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();
$useRequest = false;
$sawResponse = false;
$forceShow = (defval($_REQUEST, "forceShow") && $Me->privChair ? "&amp;forceShow=1" : "");
$linkExtra = $forceShow;


// header
function confHeader() {
    global $prow, $mode, $Conf, $linkExtra, $CurrentList;
    if ($prow)
	$title = "Paper #$prow->paperId Comments";
    else
	$title = "Paper Comments";
    $Conf->header($title, "comment", actionBar($prow, false, "comment"), false);
    if (isset($CurrentList) && $CurrentList > 0
	&& strpos($linkExtra, "ls=") === false)
	$linkExtra .= "&amp;ls=" . $CurrentList;
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID
function loadRows() {
    global $Conf, $Me, $prow, $crows, $crow, $savedCommentId, $savedCrow;
    if (!isset($_REQUEST["commentId"]) && isset($_REQUEST["c"]))
	$_REQUEST["commentId"] = $_REQUEST["c"];
    if (isset($_REQUEST["commentId"]))
	$sel = array("commentId" => $_REQUEST["commentId"]);
    else {
	maybeSearchPaperId($Me);
	$sel = array("paperId" => $_REQUEST["paperId"]);
    }
    $sel['topics'] = $sel['options'] = $sel['tags'] = 1;
    if (!(($prow = $Conf->paperRow($sel, $Me->contactId, $whyNot))
	  && $Me->canViewPaper($prow, $Conf, $whyNot)))
	errorMsgExit(whyNotText($whyNot, "view"));

    $result = $Conf->qe("select PaperComment.*, firstName, lastName, email
		from PaperComment
		join ContactInfo using (contactId)
		where paperId=$prow->paperId
		order by commentId");
    $crows = array();
    $crow = null;
    while ($row = edb_orow($result)) {
	$crows[] = $row;
	if (isset($_REQUEST['commentId']) && $row->commentId == $_REQUEST['commentId'])
	    $crow = $row;
	if (isset($savedCommentId) && $row->commentId == $savedCommentId)
	    $savedCrow = $row;
    }
    if (isset($_REQUEST['commentId']) && !$crow)
	errorMsgExit("That comment does not exist.");
}
loadRows();


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");


// set watch preference action
if (isset($_REQUEST['setwatch']) && $prow) {
    $ajax = defval($_REQUEST, "ajax", 0);
    if (!$Me->privChair
	|| ($contactId = cvtint($_REQUEST["contactId"])) <= 0)
	$contactId = $Me->contactId;
    if (defval($_REQUEST, 'watch'))
	$q = "insert into PaperWatch (paperId, contactId, watch) values ($prow->paperId, $contactId, " . (WATCH_COMMENTSET | WATCH_COMMENT) . ") on duplicate key update watch = watch | " . (WATCH_COMMENTSET | WATCH_COMMENT);
    else
	$q = "insert into PaperWatch (paperId, contactId, watch) values ($prow->paperId, $contactId, " . WATCH_COMMENTSET . ") on duplicate key update watch = (watch | " . WATCH_COMMENTSET . ") & " . (~WATCH_COMMENT & 127);
    $Conf->qe($q, "while saving watch preference");
    if ($OK)
	$Conf->confirmMsg("Mail preference saved.");
    if ($ajax)
	$Conf->ajaxExit(array("ok" => $OK));
}


// send watch messages
function setReviewInfo($dst, $src) {
    $dst->myReviewType = $src->myReviewType;
    $dst->myReviewSubmitted = $src->myReviewSubmitted;
    $dst->myReviewNeedsSubmit = $src->myReviewNeedsSubmit;
    $dst->conflictType = $src->conflictType;
}

function watch() {
    global $Conf, $Me, $prow, $savedCrow;

    if ($Conf->setting("allowPaperOption") < 6 || !$savedCrow)
	return;

    $result = $Conf->qe("select ContactInfo.contactId,
		firstName, lastName, email, password, roles, defaultWatch,
		reviewType as myReviewType,
		reviewSubmitted as myReviewSubmitted,
		reviewNeedsSubmit as myReviewNeedsSubmit,
		commentId, conflictType, watch
		from ContactInfo
		left join PaperReview on (PaperReview.paperId=$prow->paperId and PaperReview.contactId=ContactInfo.contactId)
		left join PaperComment on (PaperComment.paperId=$prow->paperId and PaperComment.contactId=ContactInfo.contactId)
		left join PaperConflict on (PaperConflict.paperId=$prow->paperId and PaperConflict.contactId=ContactInfo.contactId)
		left join PaperWatch on (PaperWatch.paperId=$prow->paperId and PaperWatch.contactId=ContactInfo.contactId)
		where conflictType>=" . CONFLICT_AUTHOR . " or reviewType is not null or watch is not null or commentId is not null");
    
    $sendNamed = array();
    $sendAnon = array();
    $saveProw = (object) null;
    $lastContactId = 0;
    setReviewInfo($saveProw, $prow);

    while (($row = edb_orow($result))) {
	if ($row->contactId == $lastContactId)
	    continue;
	$lastContactId = $row->contactId;
	if ($row->watch & WATCH_COMMENTSET) {
	    if (!($row->watch & WATCH_COMMENT))
		continue;
	} else {
	    if (!($row->defaultWatch & WATCH_COMMENT))
		continue;
	}

	$minic = Contact::makeMinicontact($row);
	setReviewInfo($prow, $row);
	if ($minic->canViewComment($prow, $savedCrow, $Conf)
	    && $minic->contactId != $Me->contactId) {
	    require_once("Code/mailtemplate.inc");
	    Mailer::send("@commentnotify", $prow, $minic, null, array("commentId" => $savedCrow->commentId));
	}
    }

    setReviewInfo($prow, $saveProw);
}


// update comment action
function saveComment($text) {
    global $Me, $Conf, $prow, $crow, $savedCommentId;

    // options
    $forReviewers = (defval($_REQUEST, "forReviewers") ? 1 : 0);
    $forAuthors = (defval($_REQUEST, "forAuthors") ? 1 : 0);
    $blind = 0;
    if ($Conf->blindReview() > 1
	|| ($Conf->blindReview() == 1 && defval($_REQUEST, "blind")))
	$blind = 1;
    if (isset($_REQUEST["response"])) {
	$forAuthors = 2;
	$blind = $prow->blind;	// use $prow->blind setting on purpose
    }

    // query
    if (!$text) {
	$change = true;
	$q = "delete from PaperComment where commentId=$crow->commentId";
    } else if (!$crow) {
	$change = true;
	$q = "insert into PaperComment (contactId, paperId, timeModified, comment, forReviewers, forAuthors, blind) values ($Me->contactId, $prow->paperId, " . time() . ", '" . sqlq($text) . "', $forReviewers, $forAuthors, $blind)";
    } else {
	$change = ($crow->forAuthors != $forAuthors);
	$q = "update PaperComment set timeModified=" . time() . ", comment='" . sqlq($text) . "', forReviewers=$forReviewers, forAuthors=$forAuthors, blind=$blind where commentId=$crow->commentId";
    }

    $while = "while saving comment";
    $result = $Conf->qe($q, $while);
    if (!$result)
	return;

    // comment ID
    if ($crow)
	$savedCommentId = $crow->commentId;
    else if (!($savedCommentId = $Conf->lastInsertId($while)))
	return;

    // log, end
    $action = ($text == "" ? "deleted" : "saved");
    $Conf->confirmMsg("Comment $action");
    $Conf->log("Comment $savedCommentId $action", $Me, $prow->paperId);

    // adjust comment counts
    if ($change) {
	$Conf->q("unlock tables");	// just in case
	$Conf->qe("update Paper set numComments=(select count(commentId) from PaperComment where paperId=$prow->paperId), numAuthorComments=(select count(commentId) from PaperComment where paperId=$prow->paperId and forAuthors>0) where paperId=$prow->paperId", $while);
    }
    
    unset($_REQUEST["commentId"]);
    unset($_REQUEST["c"]);
    $_REQUEST["paperId"] = $prow->paperId;
}

function saveResponse($text) {
    global $Me, $Conf, $prow, $crow, $linkExtra, $ConfSiteSuffix;

    // make sure there is exactly one response
    if (!$crow) {
	$result = $Conf->qe("select commentId from PaperComment where paperId=$prow->paperId and forAuthors>1");
	if (($row = edb_row($result)))
	    return $Conf->errorMsg("A paper response has already been entered.  <a href=\"comment$ConfSiteSuffix?c=$row[0]$linkExtra\">Edit that response</a>");
    }

    saveComment($text);
}

if (isset($_REQUEST['submit']) && defval($_REQUEST, 'response')) {
    if (!$Me->canRespond($prow, $crow, $Conf, $whyNot, true)) {
	$Conf->errorMsg(whyNotText($whyNot, "respond"));
	$useRequest = true;
    } else if (!($text = defval($_REQUEST, 'comment')) && !$crow) {
	$Conf->errorMsg("Enter a comment.");
	$useRequest = true;
    } else {
	$Conf->qe("lock tables Paper write, PaperComment write, ActionLog write");
	saveResponse($text);
	$Conf->qe("unlock tables");
	loadRows();
	watch();
    }
} else if (isset($_REQUEST['submit'])) {
    if (!$Me->canSubmitComment($prow, $crow, $Conf, $whyNot)) {
	$Conf->errorMsg(whyNotText($whyNot, "comment"));
	$useRequest = true;
    } else if (!($text = defval($_REQUEST, 'comment')) && !$crow) {
	$Conf->errorMsg("Enter a comment.");
	$useRequest = true;
    } else {
	saveComment($text);
	loadRows();
	watch();
    }
} else if (isset($_REQUEST['delete']) && $crow) {
    if (!$Me->canSubmitComment($prow, $crow, $Conf, $whyNot)) {
	$Conf->errorMsg(whyNotText($whyNot, "comment"));
	$useRequest = true;
    } else {
	saveComment("");
	loadRows();
    }
}


// set tags action (see also review.php)
if (isset($_REQUEST["settags"])) {
    if ($Me->canSetTags($prow, $Conf, $forceShow)) {
	require_once("Code/tags.inc");
	setTags($prow->paperId, defval($_REQUEST, "tags", ""), 'p', $Me->privChair);
	loadRows();
    } else
	$Conf->errorMsg("You cannot set tags for paper #$prow->paperId." . ($Me->privChair ? "  (<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\">Override conflict</a>)" : ""));
}


// page header
confHeader();


// paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, $forceShow);
$authorsFolded = (!$canViewAuthors && $Me->privChair && paperBlind($prow) ? 1 : 2);
$paperTable = new PaperTable(false, false, true, $authorsFolded, "review");
$paperTable->watchCheckbox = WATCH_COMMENT;


// begin table
$paperTable->echoDivEnter();
echo "<table class='paper'>\n\n";
$Conf->tableMsg(2, $paperTable);


// title
echo "<tr class='id'>\n  <td class='caption'><h2>#$prow->paperId</h2></td>\n";
echo "  <td class='entry' colspan='2'><h2>";
$paperTable->echoTitle($prow);
echo "<img id='foldsession.paper9' alt='' src='${ConfSiteBase}sessionvar$ConfSiteSuffix?var=foldreviewp&amp;val=", defval($_SESSION, "foldreviewp", 1), "&amp;cache=1' width='1' height='1' />";
echo "</h2></td>\n</tr>\n\n";


// paper body
$paperTable->echoPaperRow($prow, PaperTable::STATUS_CONFLICTINFO_PC);
if ($canViewAuthors || $Me->privChair) {
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoContactAuthor($prow);
    $paperTable->echoCollaborators($prow);
}
$paperTable->echoAbstractRow($prow);
$paperTable->echoTopics($prow);
$paperTable->echoOptions($prow, $Me->privChair);
if ($Me->canViewTags($prow, $Conf, $forceShow))
    $paperTable->echoTags($prow, "${ConfSiteBase}comment$ConfSiteSuffix?p=$prow->paperId$linkExtra");
if ($Me->privChair)
    $paperTable->echoPCConflicts($prow, true);
if ($crow)
    echo "<tr>\n  <td class='caption'></td>\n  <td class='entry'><a href='comment$ConfSiteSuffix?p=$prow->paperId$linkExtra'>All comments</a></td>\n</tr>\n\n";
if ($Me->privChair && $prow->conflictType > 0 && !$forceShow) {
    $a = "<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\">";
    echo "<tr>\n  <td class='caption'></td>\n  <td class='entry'>", $a, $Conf->cacheableImage("override24.png", "[Override]", null, "dlimg"), "</a>&nbsp;", $a, "Override conflict</a> to see all comments and allow editing</a><div class='smgap'></div></td>\n</tr>\n\n";
}


// exit on certain errors
if (!$Me->canViewComment($prow, $crow, $Conf, $whyNot))
    errorMsgExit(whyNotText($whyNot, "comment"));
if ($Me->privChair && $prow->conflictType > 0 && !$Me->canViewComment($prow, $crow, $Conf, $fakeWhyNot, true))
    $Conf->infoMsg("You have explicitly overridden your conflict and are able to view and edit comments for this paper.");

// close table 
echo "<tr class='last'><td class='caption'></td><td class='entry'></td></tr>\n";
echo "</table>";
$paperTable->echoDivExit();
$Conf->tableMsg(0);



// review information
// XXX reviewer ID
// XXX "<td class='entry'>", contactHtml($rrow), "</td>"

function commentIdentityTime($prow, $crow, &$sep) {
    global $Conf, $Me;
    $xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
    if ($crow && $Me->canViewCommentIdentity($prow, $crow, $Conf)) {
	$blind = ($crow->blind && $crow->forAuthors > 0);
	echo ($blind ? "[" : ""), "by ", contactHtml($crow);
	$sep = ($blind ? "]" : "") . $xsep;
    } else if ($crow && $Me->privChair) {
	echo "<span id='foldcid$crow->commentId' class='fold4c'>",
	    foldbutton("cid$crow->commentId", "comment", 4),
	    " <span class='ellipsis4'><i>Hidden for blind review</i></span>",
	    "<span class='extension4'>", contactHtml($crow), "</span>",
	    "</span>";
	$sep = $xsep;
    }
    if ($crow && $crow->timeModified > 0) {
	echo $sep, $Conf->printableTime($crow->timeModified);
	$sep = $xsep;
    }
}

function commentView($prow, $crow, $editMode) {
    global $Conf, $ConfSiteSuffix, $Me, $rf, $forceShow, $linkExtra, $useRequest, $anyComment;

    if ($crow && $crow->forAuthors > 1)
	return responseView($prow, $crow, $editMode);
    
    if (!$Me->canViewComment($prow, $crow, $Conf))
	return;
    if ($editMode && !$Me->canComment($prow, $crow, $Conf))
	$editMode = false;
    $anyComment = true;
    
    if ($editMode) {
	echo "<form action='comment$ConfSiteSuffix?";
	if ($crow)
	    echo "c=$crow->commentId";
	else
	    echo "p=$prow->paperId";
	echo "$linkExtra&amp;post=1' method='post' enctype='multipart/form-data' accept-charset='UTF-8'>\n";
    }

    echo "<table class='comment'>
<tr class='id'>
  <td class='caption'><h3";
    if ($crow)
	echo " id='comment$crow->commentId'";
    if ($editMode)
	echo " class='editable'";
    echo ">", ($crow ? "Comment" : "Add Comment"), "</h3></td>
  <td class='entry'>";
    $sep = "";
    commentIdentityTime($prow, $crow, $sep);
    if (!$crow || $prow->conflictType >= CONFLICT_AUTHOR)
	/* do nothing */;
    else if (!$crow->forAuthors && !$crow->forReviewers)
	echo $sep, "For PC only";
    else {
	echo $sep, "For PC";
	if ($crow->forReviewers)
	    echo " + reviewers";
	if ($crow->forAuthors && $crow->blind)
	    echo " + authors (anonymous to authors)";
	else if ($crow->forAuthors)
	    echo " + authors";
    }
    $xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
    if ($crow && ($crow->contactId == $Me->contactId || $Me->privChair) && !$editMode)
	echo $xsep, "<a class='button' href='comment$ConfSiteSuffix?c=$crow->commentId$linkExtra'>Edit</a>";
    echo "</td>\n</tr>\n\n";
    
    if (!$editMode) {
	echo "<tr>
  <td class='caption initial final'></td>
  <td class='entry initial final'>",
	    htmlWrapText(htmlspecialchars($crow->comment)), "</td>
</tr>
</table>\n";
	return;
    }
    
    // From here on, edit mode.
    $extraclass = " initial";
    
    if ($crow && $crow->contactId != $Me->contactId) {
	echo "<tr class='rev_rev'>
  <td class='caption$extraclass'></td>
  <td class='entry$extraclass'>";
	$Conf->infoMsg("You didn't write this comment, but as an administrator you can still make changes.");
	echo "</td>\n</tr>\n\n";
	$extraclass = "";
    }

    // form body
    echo "<tr>
  <td class='caption$extraclass'></td>
  <td class='entry$extraclass'><textarea name='comment' rows='10' cols='80'>";
    if ($useRequest)
	echo htmlspecialchars(defval($_REQUEST, 'comment'));
    else if ($crow)
	echo htmlspecialchars($crow->comment);
    echo "</textarea></td>
</tr>

<tr>
  <td class='caption'>Visibility</td>
  <td class='entry'>For PC and: <input type='checkbox' name='forReviewers' value='1'";
    if (($useRequest && defval($_REQUEST, 'forReviewers'))
	|| (!$useRequest && $crow && $crow->forReviewers)
	|| (!$useRequest && !$crow && $Conf->setting("extrev_view") > 0))
	echo " checked='checked'";
    echo " />&nbsp;Reviewers &nbsp;
    <input type='checkbox' name='forAuthors' value='1'";
    if ($useRequest ? defval($_REQUEST, 'forAuthors') : ($crow && $crow->forAuthors))
	echo " checked='checked'";
    echo " />&nbsp;Authors\n";

    // blind?
    if ($Conf->blindReview() == 1) {
	echo "<span class='lgsep'></span><input type='checkbox' name='blind' value='1'";
	if ($useRequest ? defval($_REQUEST, 'blind') : (!$crow || $crow->blind))
	    echo " checked='checked'";
	echo " />&nbsp;Anonymous to authors\n";
    }
    
    echo "  </td>
</tr>\n\n";

    // review actions
    if (1) {
	echo "<tr class='rev_actions'>
  <td class='caption final'></td>
  <td class='entry final'><table class='pt_buttons'>
    <tr>\n";
	echo "      <td class='ptb_button'><input class='hbutton' type='submit' value='Save' name='submit' /></td>\n";
	if ($crow)
	    echo "      <td class='ptb_button'><input class='button' type='submit' value='Delete comment' name='delete' /></td>\n";
	echo "    </tr>\n  </table>\n";
	if (!$Me->timeReview($prow, null, $Conf))
	    echo "<div class='smgap'></div>",
		"<input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines";
	echo "</td>\n</tr>\n\n";
    }

    echo "</table>\n</form>\n\n";
}


function responseView($prow, $crow, $editMode) {
    global $Conf, $ConfSiteSuffix, $Me, $rf, $forceShow, $linkExtra, $useRequest, $sawResponse;

    if ($editMode && !$Me->canRespond($prow, $crow, $Conf))
	$editMode = false;
    $sawResponse = true;
    $wordlimit = $Conf->setting("resp_words", 0);
    
    if ($editMode) {
	echo "<form action='comment$ConfSiteSuffix?";
	if ($crow)
	    echo "c=$crow->commentId";
	else
	    echo "p=$prow->paperId";
	echo "$linkExtra&amp;response=1&amp;post=1' method='post' enctype='multipart/form-data' accept-charset='UTF-8'>\n";
    }

    echo "<table class='comment'>
<tr class='id'>
  <td class='caption'><h3";
    if ($crow)
	echo " id='comment$crow->commentId'";
    if ($editMode)
	echo " class='editable'";
    echo ">", ($crow ? "Response" : "Add Response"), "</h3></td>
  <td class='entry'>";
    $sep = "";
    commentIdentityTime($prow, $crow, $sep);
    if ($crow && ($prow->conflictType >= CONFLICT_AUTHOR || $Me->privChair)
	&& !$editMode && $Me->canRespond($prow, $crow, $Conf))
	echo $sep, "<a class='button' href='comment$ConfSiteSuffix?c=$crow->commentId$linkExtra'>Edit</a>";
    echo "</td>\n</tr>\n\n";

    if (!$editMode) {
	echo "<tr>
  <td class='caption initial final'></td>
  <td class='entry initial final'>";
	if ($Me->privChair && $crow->forReviewers < 1)
	    echo "<i>The <a href='comment$ConfSiteSuffix?c=$crow->commentId$linkExtra'>authors' response</a> is not yet ready for reviewers to view.</i>";
	else if (!$Me->canViewComment($prow, $crow, $Conf))
	    echo "<i>The authors' response is not yet ready for reviewers to view.</i>";
	else
	    echo htmlWrapText(htmlspecialchars($crow->comment));
	echo "</td>
</tr>
</table>\n";
	return;
    }

    // From here on, edit mode.
    $extraclass = " initial";
    
    // form body
    echo "<tr>
  <td class='caption$extraclass'></td>
  <td class='entry$extraclass'>";

    $limittext = ($wordlimit ? ": the conference system will enforce a limit of $wordlimit words" : "");
    $Conf->infoMsg("The authors' response is a mechanism to address
reviewer concerns and correct misunderstandings.
The response should be addressed to the program committee, who
will consider it when making their decision.  Don't try to
augment the paper's content or form&mdash;the conference deadline
has passed.  Please keep the response short and to the point" . $limittext . ".");
    if ($prow->conflictType < CONFLICT_AUTHOR)
	$Conf->infoMsg("Although you aren't a contact author for this paper, as an administrator you can edit the authors' response.");
    
    echo "<textarea name='comment' rows='10' cols='80'>";
    if ($crow)
	echo htmlspecialchars($crow->comment);
    echo "</textarea></td>
</tr>\n\n";

    // review actions
    if (1) {
	echo "<tr>
  <td class='caption'></td>
  <td class='entry'><input type='checkbox' name='forReviewers' value='1' ";
	if (!$crow || $crow->forReviewers > 0)
	    echo "checked='checked' ";
	echo "/>&nbsp;The response is ready for reviewers to view.</td>
</tr><tr class='rev_actions'>
  <td class='caption final'></td>
  <td class='entry final'><table class='pt_buttons'>
    <tr>\n";
	echo "      <td class='ptb_button'><input class='hbutton' type='submit' value='Save' name='submit' /></td>\n";
	if ($crow)
	    echo "      <td class='ptb_button'><input class='button' type='submit' value='Delete response' name='delete' /></td>\n";
	echo "    </tr>\n  </table>";
	if (!$Conf->timeAuthorRespond())
	    echo "<div class='smgap'></div>",
		"<input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines";
	echo "</td>\n</tr>\n\n";
    }

    echo "<tr class='last'><td class='caption'></td></tr>\n";
    echo "</table>\n</form>\n\n";
}


if ($crow)
    commentView($prow, $crow, true);
else {
    $anyComment = false;
    foreach ($crows as $cr)
	commentView($prow, $cr, $cr->forAuthors > 1 && $prow->conflictType >= CONFLICT_AUTHOR);
    if ($Me->canComment($prow, null, $Conf))
	commentView($prow, null, true);
    if (!$sawResponse && $Conf->timeAuthorRespond()
	&& ($prow->conflictType >= CONFLICT_AUTHOR || $Me->privChair))
	responseView($prow, null, true);
    if (!$anyComment && !$sawResponse) {
	echo "<table class='comment'><tr class='id'><td></td></tr></table>\n";
	$Conf->infoMsg("No comments are available for this paper." . ($Me->privChair ? "  As administrator, you may <a href='" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "'>override your conflict</a> to enter a comment yourself." : ""));
    }
}


$Conf->footer();

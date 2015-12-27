<?php
// updateschema.php -- HotCRP function for updating old schemata
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function update_schema_create_review_form($Conf) {
    $reviewScoreNames = ["overAllMerit", "technicalMerit", "novelty",
                         "grammar", "reviewerQualification", "potential",
                         "fixability", "interestToCommunity", "longevity",
                         "likelyPresentation", "suitableForShort"];
    if (!($result = Dbl::ql("select * from ReviewFormField where fieldName!='outcome'")))
        return false;
    $rfj = (object) array();
    while (($row = edb_orow($result))) {
        $field = (object) array();
        $field->name = $row->shortName;
        if (trim($row->description) != "")
            $field->description = trim($row->description);
        if ($row->sortOrder >= 0)
            $field->position = $row->sortOrder + 1;
        if ($row->rows > 3)
            $field->display_space = (int) $row->rows;
        $field->view_score = (int) $row->authorView;
        if (in_array($row->fieldName, $reviewScoreNames)) {
            $field->options = array();
            if ((int) $row->levelChar > 1)
                $field->option_letter = (int) $row->levelChar;
        }
        $fname = $row->fieldName;
        $rfj->$fname = $field;
    }

    if (!($result = Dbl::ql("select * from ReviewFormOptions where fieldName!='outcome' order by level asc")))
        return false;
    while (($row = edb_orow($result))) {
        $fname = $row->fieldName;
        if (isset($rfj->$fname) && isset($rfj->$fname->options))
            $rfj->$fname->options[$row->level - 1] = $row->description;
    }

    return $Conf->save_setting("review_form", 1, $rfj);
}

function update_schema_create_options($Conf) {
    if (!($result = Dbl::ql("select * from OptionType")))
        return false;
    $opsj = (object) array();
    $byabbr = array();
    while (($row = edb_orow($result))) {
        // backward compatibility with old schema versions
        if (!isset($row->optionValues))
            $row->optionValues = "";
        if (!isset($row->type) && $row->optionValues == "\x7Fi")
            $row->type = 2;
        else if (!isset($row->type))
            $row->type = ($row->optionValues ? 1 : 0);

        $opj = (object) array();
        $opj->id = $row->optionId;
        $opj->name = $row->optionName;

        $abbr = PaperOption::abbreviate($opj->name, $opj->id);
        if (!@$byabbr[$abbr]) {
            $opj->abbr = $abbr;
            $byabbr[$abbr] = $opj;
        } else {
            $opj->abbr = "opt$opj->id";
            $byabbr[$abbr]->abbr = "opt" . $byabbr[$abbr]->id;
        }

        if (trim($row->description) != "")
            $opj->description = trim($row->description);

        if ($row->pcView == 2)
            $opj->view_type = "nonblind";
        else if ($row->pcView == 0)
            $opj->view_type = "admin";

        $opj->position = (int) $row->sortOrder;
        if ($row->displayType == 1)
            $opj->highlight = true;
        else if ($row->displayType == 2)
            $opj->near_submission = true;

        switch ($row->type) {
        case 0:
            $opj->type = "checkbox";
            break;
        case 1:
            $opj->type = "selector";
            $opj->selector = explode("\n", rtrim($row->optionValues));
            break;
        case 2:
            $opj->type = "numeric";
            break;
        case 3:
            $opj->type = "text";
            $opj->display_space = 1;
            break;
        case 4:
            $opj->type = "pdf";
            break;
        case 5:
            $opj->type = "slides";
            break;
        case 6:
            $opj->type = "video";
            break;
        case 7:
            $opj->type = "radio";
            $opj->selector = explode("\n", rtrim($row->optionValues));
            break;
        case 8:
            $opj->type = "text";
            $opj->display_space = 5;
            break;
        case 9:
            $opj->type = "attachments";
            break;
        case 100:
            $opj->type = "pdf";
            $opj->final = true;
            break;
        case 101:
            $opj->type = "slides";
            $opj->final = true;
            break;
        case 102:
            $opj->type = "video";
            $opj->final = true;
            break;
        }

        $oid = $opj->id;
        $opsj->$oid = $opj;
    }

    return $Conf->save_setting("options", 1, $opsj);
}

function update_schema_transfer_address($Conf) {
    $result = Dbl::ql("select * from ContactAddress");
    while (($row = edb_orow($result)))
        if (($c = Contact::find_by_id($row->contactId))) {
            $x = (object) array();
            if ($row->addressLine1 || $row->addressLine2)
                $x->address = array();
            foreach (array("addressLine1", "addressLine2") as $k)
                if ($row->$k)
                    $x->address[] = $row->$k;
            foreach (array("city" => "city", "state" => "state",
                           "zipCode" => "zip", "country" => "country") as $k => $v)
                if ($row->$k)
                    $x->$v = $row->$k;
            $c->merge_and_save_data($x);
        }
    return true;
}

function update_schema_unaccented_name($Conf) {
    if (!Dbl::ql("alter table ContactInfo add `unaccentedName` varchar(120) NOT NULL DEFAULT ''"))
        return false;

    $result = Dbl::ql("select contactId, firstName, lastName from ContactInfo");
    if (!$result)
        return false;

    $qs = $qv = array();
    while ($result && ($x = $result->fetch_row())) {
        $qs[] = "update ContactInfo set unaccentedName=? where contactId=$x[0]";
        $qv[] = Text::unaccented_name($x[1], $x[2]);
    }
    Dbl::free($result);

    $q = Dbl::format_query_apply($Conf->dblink, join(";", $qs), $qv);
    if (!$Conf->dblink->multi_query($q))
        return false;
    do {
        if ($result = $Conf->dblink->store_result())
            $result->free();
    } while ($Conf->dblink->more_results() && $Conf->dblink->next_result());
    return true;
}

function update_schema_transfer_country($Conf) {
    $result = Dbl::ql($Conf->dblink, "select * from ContactInfo where `data` is not null and `data`!='{}'");
    while ($result && ($c = $result->fetch_object("Contact"))) {
        if (($country = $c->data("country")))
            Dbl::ql($Conf->dblink, "update ContactInfo set country=? where contactId=?", $country, $c->contactId);
    }
    return true;
}

function update_schema_review_word_counts($Conf) {
    $rf = new ReviewForm($Conf->review_form_json());
    do {
        $q = array();
        $result = Dbl::ql("select * from PaperReview where reviewWordCount is null limit 32");
        while (($rrow = edb_orow($result)))
            $q[] = "update PaperReview set reviewWordCount="
                . $rf->word_count($rrow) . " where reviewId=" . $rrow->reviewId;
        Dbl::free($result);
        $Conf->dblink->multi_query(join(";", $q));
        while ($Conf->dblink->more_results())
            Dbl::free($Conf->dblink->next_result());
    } while (count($q) == 32);
}

function update_schema_version($Conf, $n) {
    if (Dbl::ql("update Settings set value=$n where name='allowPaperOption'")) {
        $Conf->settings["allowPaperOption"] = $n;
        return true;
    } else
        return false;
}

function updateSchema($Conf) {
    global $Opt, $OK;
    // avoid error message abut timezone, set to $Opt
    // (which might be overridden by database values later)
    if (function_exists("date_default_timezone_set") && @$Opt["timezone"])
        date_default_timezone_set($Opt["timezone"]);

    error_log($Opt["dbName"] . ": updating schema from version " . $Conf->settings["allowPaperOption"]);

    if ($Conf->settings["allowPaperOption"] == 6
        && Dbl::ql("alter table ReviewRequest add `reason` text"))
        update_schema_version($Conf, 7);
    if ($Conf->settings["allowPaperOption"] == 7
        && Dbl::ql("alter table PaperReview add `textField7` mediumtext NOT NULL")
        && Dbl::ql("alter table PaperReview add `textField8` mediumtext NOT NULL")
        && Dbl::ql("insert into ReviewFormField set fieldName='textField7', shortName='Additional text field'")
        && Dbl::ql("insert into ReviewFormField set fieldName='textField8', shortName='Additional text field'"))
        update_schema_version($Conf, 8);
    if ($Conf->settings["allowPaperOption"] == 8
        && Dbl::ql("alter table ReviewFormField add `levelChar` tinyint(1) NOT NULL default '0'")
        && Dbl::ql("alter table PaperReviewArchive add `textField7` mediumtext NOT NULL")
        && Dbl::ql("alter table PaperReviewArchive add `textField8` mediumtext NOT NULL"))
        update_schema_version($Conf, 9);
    if ($Conf->settings["allowPaperOption"] == 9
        && Dbl::ql("alter table Paper add `sha1` varbinary(20) NOT NULL default ''"))
        update_schema_version($Conf, 10);
    if ($Conf->settings["allowPaperOption"] == 10
        && Dbl::ql("alter table PaperReview add `reviewRound` tinyint(1) NOT NULL default '0'")
        && Dbl::ql("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'")
        && Dbl::ql("alter table PaperReview add key `reviewRound` (`reviewRound`)")
        && update_schema_version($Conf, 11)) {
        if (count($Conf->round_list()) > 1) {
            // update review rounds (XXX locking)
            $result = Dbl::ql("select paperId, tag from PaperTag where tag like '%~%'");
            $rrs = array();
            while (($row = edb_row($result))) {
                list($contact, $round) = explode("~", $row[1]);
                if (($round = array_search($round, $Conf->round_list()))) {
                    if (!isset($rrs[$round]))
                        $rrs[$round] = array();
                    $rrs[$round][] = "(contactId=$contact and paperId=$row[0])";
                }
            }
            foreach ($rrs as $round => $pairs) {
                $q = "update PaperReview set reviewRound=$round where " . join(" or ", $pairs);
                Dbl::ql($q);
            }
            $x = trim(preg_replace('/(\S+)\s*/', "tag like '%~\$1' or ", $Conf->setting_data("tag_rounds")));
            Dbl::ql("delete from PaperTag where " . substr($x, 0, strlen($x) - 3));
        }
    }
    if ($Conf->settings["allowPaperOption"] == 11
        && Dbl::ql("create table `ReviewRating` (
  `reviewId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL default '0',
  UNIQUE KEY `reviewContact` (`reviewId`,`contactId`),
  UNIQUE KEY `reviewContactRating` (`reviewId`,`contactId`,`rating`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 12);
    if ($Conf->settings["allowPaperOption"] == 12
        && Dbl::ql("alter table PaperReview add `reviewToken` int(11) NOT NULL default '0'"))
        update_schema_version($Conf, 13);
    if ($Conf->settings["allowPaperOption"] == 13
        && Dbl::ql("alter table OptionType add `optionValues` text NOT NULL default ''"))
        update_schema_version($Conf, 14);
    if ($Conf->settings["allowPaperOption"] == 14
        && Dbl::ql("insert into Settings (name, value) select 'rev_tokens', count(reviewId) from PaperReview where reviewToken!=0 on duplicate key update value=values(value)"))
        update_schema_version($Conf, 15);
    if ($Conf->settings["allowPaperOption"] == 15) {
        // It's OK if this fails!  Update 11 added reviewRound to
        // PaperReviewArchive (so old databases have the column), but I forgot
        // to upgrade schema.sql (so new databases lack the column).
        Dbl::ql("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'");
        $OK = true;
        update_schema_version($Conf, 16);
    }
    if ($Conf->settings["allowPaperOption"] == 16
        && Dbl::ql("alter table PaperReview add `reviewEditVersion` int(1) NOT NULL default '0'"))
        update_schema_version($Conf, 17);
    if ($Conf->settings["allowPaperOption"] == 17
        && Dbl::ql("alter table PaperReviewPreference add key `paperId` (`paperId`)")
        && Dbl::ql("create table PaperRank (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rank` int(11) NOT NULL,
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  KEY `paperId` (`paperId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;"))
        update_schema_version($Conf, 18);
    if ($Conf->settings["allowPaperOption"] == 18
        && Dbl::ql("alter table PaperComment add `replyTo` int(11) NOT NULL"))
        update_schema_version($Conf, 19);
    if ($Conf->settings["allowPaperOption"] == 19
        && Dbl::ql("drop table PaperRank"))
        update_schema_version($Conf, 20);
    if ($Conf->settings["allowPaperOption"] == 20
        && Dbl::ql("alter table PaperComment add `timeNotified` int(11) NOT NULL default '0'"))
        update_schema_version($Conf, 21);
    if ($Conf->settings["allowPaperOption"] == 21
        && Dbl::ql("update PaperConflict set conflictType=8 where conflictType=3"))
        update_schema_version($Conf, 22);
    if ($Conf->settings["allowPaperOption"] == 22
        && Dbl::ql("insert into ChairAssistant (contactId) select contactId from Chair on duplicate key update ChairAssistant.contactId=ChairAssistant.contactId")
        && Dbl::ql("update ContactInfo set roles=roles+2 where roles=5"))
        update_schema_version($Conf, 23);
    if ($Conf->settings["allowPaperOption"] == 23)
        update_schema_version($Conf, 24);
    if ($Conf->settings["allowPaperOption"] == 24
        && Dbl::ql("alter table ContactInfo add `preferredEmail` varchar(120)"))
        update_schema_version($Conf, 25);
    if ($Conf->settings["allowPaperOption"] == 25) {
        if ($Conf->settings["final_done"] > 0
            && !isset($Conf->settings["final_soft"])
            && Dbl::ql("insert into Settings (name, value) values ('final_soft', " . $Conf->settings["final_done"] . ") on duplicate key update value=values(value)"))
            $Conf->settings["final_soft"] = $Conf->settings["final_done"];
        update_schema_version($Conf, 26);
    }
    if ($Conf->settings["allowPaperOption"] == 26
        && Dbl::ql("alter table PaperOption add `data` text")
        && Dbl::ql("alter table OptionType add `type` tinyint(1) NOT NULL default '0'")
        && Dbl::ql("update OptionType set type=2 where optionValues='\x7Fi'")
        && Dbl::ql("update OptionType set type=1 where type=0 and optionValues!=''"))
        update_schema_version($Conf, 27);
    if ($Conf->settings["allowPaperOption"] == 27
        && Dbl::ql("alter table PaperStorage add `sha1` varbinary(20) NOT NULL default ''")
        && Dbl::ql("alter table PaperStorage add `documentType` int(3) NOT NULL default '0'")
        && Dbl::ql("update PaperStorage s, Paper p set s.sha1=p.sha1 where s.paperStorageId=p.paperStorageId and p.finalPaperStorageId=0 and s.paperStorageId>0")
        && Dbl::ql("update PaperStorage s, Paper p set s.sha1=p.sha1, s.documentType=" . DTYPE_FINAL . " where s.paperStorageId=p.finalPaperStorageId and s.paperStorageId>0"))
        update_schema_version($Conf, 28);
    if ($Conf->settings["allowPaperOption"] == 28
        && Dbl::ql("alter table OptionType add `sortOrder` tinyint(1) NOT NULL default '0'"))
        update_schema_version($Conf, 29);
    if ($Conf->settings["allowPaperOption"] == 29
        && Dbl::ql("delete from Settings where name='pldisplay_default'"))
        update_schema_version($Conf, 30);
    if ($Conf->settings["allowPaperOption"] == 30
        && Dbl::ql("DROP TABLE IF EXISTS `Formula`")
        && Dbl::ql("CREATE TABLE `Formula` (
  `formulaId` int(11) NOT NULL auto_increment,
  `name` varchar(200) NOT NULL,
  `heading` varchar(200) NOT NULL default '',
  `headingTitle` text NOT NULL default '',
  `expression` text NOT NULL,
  `authorView` tinyint(1) NOT NULL default '1',
  PRIMARY KEY  (`formulaId`),
  UNIQUE KEY `formulaId` (`formulaId`),
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 31);
    if ($Conf->settings["allowPaperOption"] == 31
        && Dbl::ql("alter table Formula add `createdBy` int(11) NOT NULL default '0'")
        && Dbl::ql("alter table Formula add `timeModified` int(11) NOT NULL default '0'")
        && Dbl::ql("alter table Formula drop index `name`"))
        update_schema_version($Conf, 32);
    if ($Conf->settings["allowPaperOption"] == 32
        && Dbl::ql("alter table PaperComment add key `timeModified` (`timeModified`)"))
        update_schema_version($Conf, 33);
    if ($Conf->settings["allowPaperOption"] == 33
        && Dbl::ql("alter table PaperComment add `paperStorageId` int(11) NOT NULL default '0'"))
        update_schema_version($Conf, 34);
    if ($Conf->settings["allowPaperOption"] == 34
        && Dbl::ql("alter table ContactInfo add `contactTags` text"))
        update_schema_version($Conf, 35);
    if ($Conf->settings["allowPaperOption"] == 35
        && Dbl::ql("alter table ContactInfo modify `defaultWatch` int(11) NOT NULL default '2'")
        && Dbl::ql("alter table PaperWatch modify `watch` int(11) NOT NULL default '0'"))
        update_schema_version($Conf, 36);
    if ($Conf->settings["allowPaperOption"] == 36
        && Dbl::ql("alter table PaperReview add `reviewNotified` int(1) default NULL")
        && Dbl::ql("alter table PaperReviewArchive add `reviewNotified` int(1) default NULL"))
        update_schema_version($Conf, 37);
    if ($Conf->settings["allowPaperOption"] == 37
        && Dbl::ql("alter table OptionType add `displayType` tinyint(1) NOT NULL default '0'"))
        update_schema_version($Conf, 38);
    if ($Conf->settings["allowPaperOption"] == 38
        && Dbl::ql("update PaperComment set forReviewers=1 where forReviewers=-1"))
        update_schema_version($Conf, 39);
    if ($Conf->settings["allowPaperOption"] == 39
        && Dbl::ql("CREATE TABLE `MailLog` (
  `mailId` int(11) NOT NULL auto_increment,
  `recipients` varchar(200) NOT NULL,
  `paperIds` text,
  `cc` text,
  `replyto` text,
  `subject` text,
  `emailBody` text,
  PRIMARY KEY  (`mailId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 40);
    if ($Conf->settings["allowPaperOption"] == 40
        && Dbl::ql("alter table Paper add `capVersion` int(1) NOT NULL default '0'"))
        update_schema_version($Conf, 41);
    if ($Conf->settings["allowPaperOption"] == 41
        && Dbl::ql("alter table Paper modify `mimetype` varchar(80) NOT NULL default ''")
        && Dbl::ql("alter table PaperStorage modify `mimetype` varchar(80) NOT NULL default ''"))
        update_schema_version($Conf, 42);
    if ($Conf->settings["allowPaperOption"] == 42
        && Dbl::ql("alter table PaperComment add `ordinal` int(11) NOT NULL default '0'"))
        update_schema_version($Conf, 43);
    if ($Conf->settings["allowPaperOption"] == 42
        && ($result = Dbl::ql("describe PaperComment `ordinal`"))
        && ($o = edb_orow($result))
        && substr($o->Type, 0, 3) == "int"
        && (!$o->Null || $o->Null == "NO")
        && (!$o->Default || $o->Default == "0"))
        update_schema_version($Conf, 43);
    if ($Conf->settings["allowPaperOption"] == 43
        && Dbl::ql("alter table Paper add `withdrawReason` text"))
        update_schema_version($Conf, 44);
    if ($Conf->settings["allowPaperOption"] == 44
        && Dbl::ql("alter table PaperStorage add `filename` varchar(255)"))
        update_schema_version($Conf, 45);
    if ($Conf->settings["allowPaperOption"] == 45
        && Dbl::ql("alter table PaperReview add `timeRequested` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReview add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReviewArchive add `timeRequested` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReviewArchive add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReview drop column `requestedOn`")
        && Dbl::ql("alter table PaperReviewArchive drop column `requestedOn`"))
        update_schema_version($Conf, 46);
    if ($Conf->settings["allowPaperOption"] == 46
        && Dbl::ql("alter table ContactInfo add `disabled` tinyint(1) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 47);
    if ($Conf->settings["allowPaperOption"] == 47
        && Dbl::ql("delete from ti using TopicInterest ti left join TopicArea ta using (topicId) where ta.topicId is null"))
        update_schema_version($Conf, 48);
    if ($Conf->settings["allowPaperOption"] == 48
        && Dbl::ql("alter table PaperReview add `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReviewArchive add `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReviewArchive add `reviewToken` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 49);
    if ($Conf->settings["allowPaperOption"] == 49
        && Dbl::ql("alter table PaperOption drop index `paperOption`")
        && Dbl::ql("alter table PaperOption add index `paperOption` (`paperId`,`optionId`,`value`)"))
        update_schema_version($Conf, 50);
    if ($Conf->settings["allowPaperOption"] == 50
        && Dbl::ql("alter table Paper add `managerContactId` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 51);
    if ($Conf->settings["allowPaperOption"] == 51
        && Dbl::ql("alter table Paper drop column `numComments`")
        && Dbl::ql("alter table Paper drop column `numAuthorComments`"))
        update_schema_version($Conf, 52);
    // Due to a bug in the schema updater, some databases might have
    // sversion>=53 but no `PaperComment.commentType` column. Fix them.
    if (($Conf->settings["allowPaperOption"] == 52
         || ($Conf->settings["allowPaperOption"] >= 53
             && ($result = Dbl::ql("show columns from PaperComment like 'commentType'"))
             && edb_nrows($result) == 0))
        && Dbl::ql("lock tables PaperComment write, Settings write")
        && Dbl::ql("alter table PaperComment add `commentType` int(11) NOT NULL DEFAULT '0'")) {
        if (($new_sversion = $Conf->settings["allowPaperOption"]) < 53)
            $new_sversion = 53;
        $result = Dbl::ql("show columns from PaperComment like 'forAuthors'");
        if ((!$result
             || edb_nrows($result) == 0
             || (Dbl::ql("update PaperComment set commentType=" . (COMMENTTYPE_AUTHOR | COMMENTTYPE_RESPONSE) . " where forAuthors=2")
                 && Dbl::ql("update PaperComment set commentType=commentType|" . COMMENTTYPE_DRAFT . " where forAuthors=2 and forReviewers=0")
                 && Dbl::ql("update PaperComment set commentType=" . COMMENTTYPE_ADMINONLY . " where forAuthors=0 and forReviewers=2")
                 && Dbl::ql("update PaperComment set commentType=" . COMMENTTYPE_PCONLY . " where forAuthors=0 and forReviewers=0")
                 && Dbl::ql("update PaperComment set commentType=" . COMMENTTYPE_REVIEWER . " where forAuthors=0 and forReviewers=1")
                 && Dbl::ql("update PaperComment set commentType=" . COMMENTTYPE_AUTHOR . " where forAuthors!=0 and forAuthors!=2")
                 && Dbl::ql("update PaperComment set commentType=commentType|" . COMMENTTYPE_BLIND . " where blind=1")))
            && Dbl::ql("update Settings set value=$new_sversion where name='allowPaperOption'"))
            $Conf->settings["allowPaperOption"] = $new_sversion;
    }
    if ($Conf->settings["allowPaperOption"] < 53)
        Dbl::qx_raw($Conf->dblink, "alter table PaperComment drop column `commentType`");
    Dbl::ql("unlock tables");
    if ($Conf->settings["allowPaperOption"] == 53
        && Dbl::ql("alter table PaperComment drop column `forReviewers`")
        && Dbl::ql("alter table PaperComment drop column `forAuthors`")
        && Dbl::ql("alter table PaperComment drop column `blind`"))
        update_schema_version($Conf, 54);
    if ($Conf->settings["allowPaperOption"] == 54
        && Dbl::ql("alter table PaperStorage add `infoJson` varchar(255) DEFAULT NULL"))
        update_schema_version($Conf, 55);
    if ($Conf->settings["allowPaperOption"] == 55
        && Dbl::ql("alter table ContactInfo modify `password` varbinary(2048) NOT NULL"))
        update_schema_version($Conf, 56);
    if ($Conf->settings["allowPaperOption"] == 56
        && Dbl::ql("alter table Settings modify `data` blob"))
        update_schema_version($Conf, 57);
    if ($Conf->settings["allowPaperOption"] == 57
        && Dbl::ql("DROP TABLE IF EXISTS `Capability`")
        && Dbl::ql("CREATE TABLE `Capability` (
  `capabilityId` int(11) NOT NULL AUTO_INCREMENT,
  `capabilityType` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `timeExpires` int(11) NOT NULL,
  `salt` varbinary(255) NOT NULL,
  `data` blob,
  PRIMARY KEY (`capabilityId`),
  UNIQUE KEY `capabilityId` (`capabilityId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8")
        && Dbl::ql("DROP TABLE IF EXISTS `CapabilityMap`")
        && Dbl::ql("CREATE TABLE `CapabilityMap` (
  `capabilityValue` varbinary(255) NOT NULL,
  `capabilityId` int(11) NOT NULL,
  `timeExpires` int(11) NOT NULL,
  PRIMARY KEY (`capabilityValue`),
  UNIQUE KEY `capabilityValue` (`capabilityValue`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 58);
    if ($Conf->settings["allowPaperOption"] == 58
        && Dbl::ql("alter table PaperReview modify `paperSummary` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `commentsToAuthor` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `commentsToPC` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `commentsToAddress` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `weaknessOfPaper` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `strengthOfPaper` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `textField7` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `textField8` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `paperSummary` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToAuthor` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToPC` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToAddress` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `weaknessOfPaper` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `strengthOfPaper` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `textField7` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `textField8` mediumtext DEFAULT NULL"))
        update_schema_version($Conf, 59);
    if ($Conf->settings["allowPaperOption"] == 59
        && Dbl::ql("alter table ActionLog modify `action` varbinary(4096) NOT NULL")
        && Dbl::ql("alter table ContactInfo modify `note` varbinary(4096) DEFAULT NULL")
        && Dbl::ql("alter table ContactInfo modify `collaborators` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table ContactInfo modify `contactTags` varbinary(4096) DEFAULT NULL")
        && Dbl::ql("alter table Formula modify `headingTitle` varbinary(4096) NOT NULL")
        && Dbl::ql("alter table Formula modify `expression` varbinary(4096) NOT NULL")
        && Dbl::ql("alter table OptionType modify `description` varbinary(8192) DEFAULT NULL")
        && Dbl::ql("alter table OptionType modify `optionValues` varbinary(8192) NOT NULL")
        && Dbl::ql("alter table PaperReviewRefused modify `reason` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table ReviewFormField modify `description` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table ReviewFormOptions modify `description` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table ReviewRequest modify `reason` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table Settings modify `data` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table ContactAddress modify `addressLine1` varchar(2048) NOT NULL")
        && Dbl::ql("alter table ContactAddress modify `addressLine2` varchar(2048) NOT NULL")
        && Dbl::ql("alter table ContactAddress modify `city` varchar(2048) NOT NULL")
        && Dbl::ql("alter table ContactAddress modify `state` varchar(2048) NOT NULL")
        && Dbl::ql("alter table ContactAddress modify `zipCode` varchar(2048) NOT NULL")
        && Dbl::ql("alter table ContactAddress modify `country` varchar(2048) NOT NULL")
        && Dbl::ql("alter table PaperTopic modify `topicId` int(11) NOT NULL")
        && Dbl::ql("alter table PaperTopic modify `paperId` int(11) NOT NULL")
        && Dbl::ql("drop table if exists ChairTag"))
        update_schema_version($Conf, 60);
    if ($Conf->settings["allowPaperOption"] == 60
        && Dbl::ql("insert into Settings (name,value,data) select concat('msg.',substr(name,1,length(name)-3)), value, data from Settings where name='homemsg' or name='conflictdefmsg'")
        && Dbl::ql("update Settings set value=61 where name='allowPaperOption'")) {
        foreach (array("conflictdef", "home") as $k)
            if (isset($Conf->settingTexts["${k}msg"]))
                $Conf->settingTexts["msg.$k"] = $Conf->settingTexts["${k}msg"];
        $Conf->settings["allowPaperOption"] = 61;
    }
    if ($Conf->settings["allowPaperOption"] == 61
        && Dbl::ql("alter table Capability modify `data` varbinary(4096) DEFAULT NULL"))
        update_schema_version($Conf, 62);
    if (!isset($Conf->settings["outcome_map"])) {
        $ojson = array();
        $result = Dbl::ql("select * from ReviewFormOptions where fieldName='outcome'");
        while (($row = edb_orow($result)))
            $ojson[$row->level] = $row->description;
        $Conf->save_setting("outcome_map", 1, $ojson);
    }
    if ($Conf->settings["allowPaperOption"] == 62
        && isset($Conf->settings["outcome_map"]))
        update_schema_version($Conf, 63);
    if (!isset($Conf->settings["review_form"])
        && $Conf->settings["allowPaperOption"] < 65)
        update_schema_create_review_form($Conf);
    if ($Conf->settings["allowPaperOption"] == 63
        && isset($Conf->settings["review_form"]))
        update_schema_version($Conf, 64);
    if ($Conf->settings["allowPaperOption"] == 64
        && Dbl::ql("drop table if exists `ReviewFormField`")
        && Dbl::ql("drop table if exists `ReviewFormOptions`"))
        update_schema_version($Conf, 65);
    if (!isset($Conf->settings["options"])
        && $Conf->settings["allowPaperOption"] < 67)
        update_schema_create_options($Conf);
    if ($Conf->settings["allowPaperOption"] == 65
        && isset($Conf->settings["options"]))
        update_schema_version($Conf, 66);
    if ($Conf->settings["allowPaperOption"] == 66
        && Dbl::ql("drop table if exists `OptionType`"))
        update_schema_version($Conf, 67);
    if ($Conf->settings["allowPaperOption"] == 67
        && Dbl::ql("alter table PaperComment modify `comment` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table PaperComment add `commentTags` varbinary(1024) DEFAULT NULL"))
        update_schema_version($Conf, 68);
    if ($Conf->settings["allowPaperOption"] == 68
        && Dbl::ql("alter table PaperReviewPreference add `expertise` int(4) DEFAULT NULL"))
        update_schema_version($Conf, 69);
    if ($Conf->settings["allowPaperOption"] == 69
        && Dbl::ql("alter table Paper drop column `pcPaper`"))
        update_schema_version($Conf, 70);
    if ($Conf->settings["allowPaperOption"] == 70
        && Dbl::ql("alter table ContactInfo modify `voicePhoneNumber` varbinary(256) DEFAULT NULL")
        && Dbl::ql("alter table ContactInfo modify `faxPhoneNumber` varbinary(256) DEFAULT NULL")
        && Dbl::ql("alter table ContactInfo modify `collaborators` varbinary(8192) DEFAULT NULL")
        && Dbl::ql("alter table ContactInfo drop column `note`")
        && Dbl::ql("alter table ContactInfo add `data` varbinary(32767) DEFAULT NULL"))
        update_schema_version($Conf, 71);
    if ($Conf->settings["allowPaperOption"] == 71
        && Dbl::ql("alter table Settings modify `name` varbinary(256) DEFAULT NULL")
        && Dbl::ql("update Settings set name=rtrim(name)"))
        update_schema_version($Conf, 72);
    if ($Conf->settings["allowPaperOption"] == 72
        && Dbl::ql("update TopicInterest set interest=-2 where interest=0")
        && Dbl::ql("update TopicInterest set interest=4 where interest=2")
        && Dbl::ql("delete from TopicInterest where interest=1"))
        update_schema_version($Conf, 73);
    if ($Conf->settings["allowPaperOption"] == 73
        && Dbl::ql("alter table PaperStorage add `size` bigint(11) DEFAULT NULL")
        && Dbl::ql("update PaperStorage set `size`=length(paper) where paper is not null"))
        update_schema_version($Conf, 74);
    if ($Conf->settings["allowPaperOption"] == 74
        && Dbl::ql("alter table ContactInfo drop column `visits`"))
        update_schema_version($Conf, 75);
    if ($Conf->settings["allowPaperOption"] == 75) {
        foreach (array("capability_gc", "s3_scope", "s3_signing_key") as $k)
            if (isset($Conf->settings[$k])) {
                $Conf->save_setting("__" . $k, $Conf->settings[$k], @$Conf->settingTexts[$k]);
                $Conf->save_setting($k, null);
            }
        $Conf->save_setting("allowPaperOption", 76);
    }
    if ($Conf->settings["allowPaperOption"] == 76
        && Dbl::ql("update PaperReviewPreference set expertise=-expertise"))
        update_schema_version($Conf, 77);
    if ($Conf->settings["allowPaperOption"] == 77
        && Dbl::ql("alter table MailLog add `q` varchar(4096)"))
        update_schema_version($Conf, 78);
    if ($Conf->settings["allowPaperOption"] == 78
        && Dbl::ql("alter table MailLog add `t` varchar(200)"))
        update_schema_version($Conf, 79);
    if ($Conf->settings["allowPaperOption"] == 79
        && Dbl::ql("alter table ContactInfo add `passwordTime` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 80);
    if ($Conf->settings["allowPaperOption"] == 80
        && Dbl::ql("alter table PaperReview modify `reviewRound` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReviewArchive modify `reviewRound` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 81);
    if ($Conf->settings["allowPaperOption"] == 81
        && Dbl::ql("alter table PaperStorage add `filterType` int(3) DEFAULT NULL")
        && Dbl::ql("alter table PaperStorage add `originalStorageId` int(11) DEFAULT NULL"))
        update_schema_version($Conf, 82);
    if ($Conf->settings["allowPaperOption"] == 82
        && Dbl::ql("update Settings set name='msg.resp_instrux' where name='msg.responseinstructions'"))
        update_schema_version($Conf, 83);
    if ($Conf->settings["allowPaperOption"] == 83
        && Dbl::ql("alter table PaperComment add `commentRound` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 84);
    if ($Conf->settings["allowPaperOption"] == 84
        && Dbl::ql("insert ignore into Settings (name, value) select 'resp_active', value from Settings where name='resp_open'"))
        update_schema_version($Conf, 85);
    if ($Conf->settings["allowPaperOption"] == 85
        && Dbl::ql("DROP TABLE IF EXISTS `PCMember`")
        && Dbl::ql("DROP TABLE IF EXISTS `ChairAssistant`")
        && Dbl::ql("DROP TABLE IF EXISTS `Chair`"))
        update_schema_version($Conf, 86);
    if ($Conf->settings["allowPaperOption"] == 86
        && update_schema_transfer_address($Conf))
        update_schema_version($Conf, 87);
    if ($Conf->settings["allowPaperOption"] == 87
        && Dbl::ql("DROP TABLE IF EXISTS `ContactAddress`"))
        update_schema_version($Conf, 88);
    if ($Conf->settings["allowPaperOption"] == 88
        && Dbl::ql("alter table ContactInfo drop key `name`")
        && Dbl::ql("alter table ContactInfo drop key `affiliation`")
        && Dbl::ql("alter table ContactInfo drop key `email_3`")
        && Dbl::ql("alter table ContactInfo drop key `firstName_2`")
        && Dbl::ql("alter table ContactInfo drop key `lastName`"))
        update_schema_version($Conf, 89);
    if ($Conf->settings["allowPaperOption"] == 89
        && update_schema_unaccented_name($Conf))
        update_schema_version($Conf, 90);
    if ($Conf->settings["allowPaperOption"] == 90
        && Dbl::ql("alter table PaperReview add `reviewAuthorSeen` int(11) DEFAULT NULL"))
        update_schema_version($Conf, 91);
    if ($Conf->settings["allowPaperOption"] == 91
        && Dbl::ql("alter table PaperReviewArchive add `reviewAuthorSeen` int(11) DEFAULT NULL"))
        update_schema_version($Conf, 92);
    if ($Conf->settings["allowPaperOption"] == 92
        && Dbl::ql("alter table Paper drop key `titleAbstractText`")
        && Dbl::ql("alter table Paper drop key `allText`")
        && Dbl::ql("alter table Paper drop key `authorText`")
        && Dbl::ql("alter table Paper modify `authorInformation` varbinary(8192) DEFAULT NULL")
        && Dbl::ql("alter table Paper modify `abstract` varbinary(16384) DEFAULT NULL")
        && Dbl::ql("alter table Paper modify `collaborators` varbinary(8192) DEFAULT NULL")
        && Dbl::ql("alter table Paper modify `withdrawReason` varbinary(1024) DEFAULT NULL"))
        update_schema_version($Conf, 93);
    if ($Conf->settings["allowPaperOption"] == 93
        && Dbl::ql("alter table TopicArea modify `topicName` varchar(200) DEFAULT NULL"))
        update_schema_version($Conf, 94);
    if ($Conf->settings["allowPaperOption"] == 94
        && Dbl::ql("alter table PaperOption modify `data` varbinary(32768) DEFAULT NULL")) {
        foreach (PaperOption::option_list($Conf) as $opt)
            if ($opt->type === "text")
                Dbl::ql("delete from PaperOption where optionId={$opt->id} and data=''");
        update_schema_version($Conf, 95);
    }
    if ($Conf->settings["allowPaperOption"] == 95
        && Dbl::ql("alter table Capability add unique key `salt` (`salt`)")
        && Dbl::ql("update Capability join CapabilityMap using (capabilityId) set Capability.salt=CapabilityMap.capabilityValue")
        && Dbl::ql("drop table if exists `CapabilityMap`"))
        update_schema_version($Conf, 96);
    if ($Conf->settings["allowPaperOption"] == 96
        && Dbl::ql("alter table ContactInfo add `passwordIsCdb` tinyint(1) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 97);
    if ($Conf->settings["allowPaperOption"] == 97
        && Dbl::ql("alter table PaperReview add `reviewWordCount` int(11) DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive add `reviewWordCount` int(11)  DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive drop key `reviewId`")
        && Dbl::ql("alter table PaperReviewArchive drop key `contactPaper`")
        && Dbl::ql("alter table PaperReviewArchive drop key `reviewSubmitted`")
        && Dbl::ql("alter table PaperReviewArchive drop key `reviewNeedsSubmit`")
        && Dbl::ql("alter table PaperReviewArchive drop key `reviewType`")
        && Dbl::ql("alter table PaperReviewArchive drop key `requestedBy`"))
        update_schema_version($Conf, 98);
    if ($Conf->settings["allowPaperOption"] == 98) {
        update_schema_review_word_counts($Conf);
        update_schema_version($Conf, 99);
    }
    if ($Conf->settings["allowPaperOption"] == 99
        && Dbl::ql("alter table ContactInfo ENGINE=InnoDB")
        && Dbl::ql("alter table Paper ENGINE=InnoDB")
        && Dbl::ql("alter table PaperComment ENGINE=InnoDB")
        && Dbl::ql("alter table PaperConflict ENGINE=InnoDB")
        && Dbl::ql("alter table PaperOption ENGINE=InnoDB")
        && Dbl::ql("alter table PaperReview ENGINE=InnoDB")
        && Dbl::ql("alter table PaperStorage ENGINE=InnoDB")
        && Dbl::ql("alter table PaperTag ENGINE=InnoDB")
        && Dbl::ql("alter table PaperTopic ENGINE=InnoDB")
        && Dbl::ql("alter table Settings ENGINE=InnoDB"))
        update_schema_version($Conf, 100);
    if ($Conf->settings["allowPaperOption"] == 100
        && Dbl::ql("alter table ActionLog ENGINE=InnoDB")
        && Dbl::ql("alter table Capability ENGINE=InnoDB")
        && Dbl::ql("alter table Formula ENGINE=InnoDB")
        && Dbl::ql("alter table MailLog ENGINE=InnoDB")
        && Dbl::ql("alter table PaperReviewArchive ENGINE=InnoDB")
        && Dbl::ql("alter table PaperReviewPreference ENGINE=InnoDB")
        && Dbl::ql("alter table PaperReviewRefused ENGINE=InnoDB")
        && Dbl::ql("alter table PaperWatch ENGINE=InnoDB")
        && Dbl::ql("alter table ReviewRating ENGINE=InnoDB")
        && Dbl::ql("alter table ReviewRequest ENGINE=InnoDB")
        && Dbl::ql("alter table TopicArea ENGINE=InnoDB")
        && Dbl::ql("alter table TopicInterest ENGINE=InnoDB"))
        update_schema_version($Conf, 101);
    if ($Conf->settings["allowPaperOption"] == 101
        && Dbl::ql("alter table ActionLog modify `ipaddr` varbinary(32) DEFAULT NULL")
        && Dbl::ql("alter table MailLog modify `recipients` varbinary(200) NOT NULL")
        && Dbl::ql("alter table MailLog modify `q` varbinary(4096) DEFAULT NULL")
        && Dbl::ql("alter table MailLog modify `t` varbinary(200) DEFAULT NULL")
        && Dbl::ql("alter table Paper modify `mimetype` varbinary(80) NOT NULL DEFAULT ''")
        && Dbl::ql("alter table PaperStorage modify `mimetype` varbinary(80) NOT NULL DEFAULT ''")
        && Dbl::ql("alter table PaperStorage modify `filename` varbinary(255) DEFAULT NULL")
        && Dbl::ql("alter table PaperStorage modify `infoJson` varbinary(8192) DEFAULT NULL"))
        update_schema_version($Conf, 102);
    if ($Conf->settings["allowPaperOption"] == 102
        && Dbl::ql("alter table PaperReview modify `paperSummary` mediumblob")
        && Dbl::ql("alter table PaperReview modify `commentsToAuthor` mediumblob")
        && Dbl::ql("alter table PaperReview modify `commentsToPC` mediumblob")
        && Dbl::ql("alter table PaperReview modify `commentsToAddress` mediumblob")
        && Dbl::ql("alter table PaperReview modify `weaknessOfPaper` mediumblob")
        && Dbl::ql("alter table PaperReview modify `strengthOfPaper` mediumblob")
        && Dbl::ql("alter table PaperReview modify `textField7` mediumblob")
        && Dbl::ql("alter table PaperReview modify `textField8` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `paperSummary` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToAuthor` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToPC` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToAddress` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `weaknessOfPaper` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `strengthOfPaper` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `textField7` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `textField8` mediumblob"))
        update_schema_version($Conf, 103);
    if ($Conf->settings["allowPaperOption"] == 103
        && Dbl::ql("alter table Paper modify `title` varbinary(256) DEFAULT NULL")
        && Dbl::ql("alter table Paper drop key `title`"))
        update_schema_version($Conf, 104);
    if ($Conf->settings["allowPaperOption"] == 104
        && Dbl::ql("alter table PaperReview add `reviewFormat` tinyint(1) DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive add `reviewFormat` tinyint(1) DEFAULT NULL"))
        update_schema_version($Conf, 105);
    if ($Conf->settings["allowPaperOption"] == 105
        && Dbl::ql("alter table PaperComment add `commentFormat` tinyint(1) DEFAULT NULL"))
        update_schema_version($Conf, 106);
    if ($Conf->settings["allowPaperOption"] == 106
        && Dbl::ql("alter table PaperComment add `authorOrdinal` int(11) NOT NULL default '0'")
        && Dbl::ql("update PaperComment set authorOrdinal=ordinal where commentType>=" . COMMENTTYPE_AUTHOR))
        update_schema_version($Conf, 107);

    // repair missing comment ordinals; reset incorrect `ordinal`s for
    // author-visible comments
    if ($Conf->settings["allowPaperOption"] == 107) {
        $result = Dbl::ql("select paperId, commentId from PaperComment where ordinal=0 and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR . " order by commentId");
        while (($row = edb_row($result))) {
            Dbl::ql("update PaperComment,
(select coalesce(count(commentId),0) commentCount from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR . " and commentId<$row[1])
    where Paper.paperId=$row[0] group by Paper.paperId) t
set ordinal=(t.commentCount+1) where commentId=$row[1]");
        }

        $result = Dbl::ql("select paperId, commentId from PaperComment where ordinal=0 and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_AUTHOR . " order by commentId");
        while (($row = edb_row($result))) {
            Dbl::ql("update PaperComment,
(select coalesce(count(commentId),0) commentCount from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_AUTHOR . " and commentId<$row[1])
    where Paper.paperId=$row[0] group by Paper.paperId) t
set authorOrdinal=(t.commentCount+1) where commentId=$row[1]");
        }

        $result = Dbl::ql("select paperId, commentId from PaperComment where ordinal=authorOrdinal and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_AUTHOR . " order by commentId");
        while (($row = edb_row($result))) {
            Dbl::ql("update PaperComment,
(select coalesce(max(ordinal),0) maxOrdinal from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR . " and commentId<$row[1])
    where Paper.paperId=$row[0] group by Paper.paperId) t
set ordinal=(t.maxOrdinal+1) where commentId=$row[1]");
        }

        update_schema_version($Conf, 108);
    }

    // contact tags format change
    if ($Conf->settings["allowPaperOption"] == 108
        && Dbl::ql("update ContactInfo set contactTags=substr(replace(contactTags, ' ', '#0 ') from 3)")
        && Dbl::ql("update ContactInfo set contactTags=replace(contactTags, '#0#0 ', '#0 ')"))
        update_schema_version($Conf, 109);
    if ($Conf->settings["allowPaperOption"] == 109
        && Dbl::ql("alter table PaperTag modify `tagIndex` float NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 110);
    if ($Conf->settings["allowPaperOption"] == 110
        && Dbl::ql("alter table ContactInfo drop `faxPhoneNumber`")
        && Dbl::ql("alter table ContactInfo add `country` varbinary(256) default null")
        && update_schema_transfer_country($Conf))
        update_schema_version($Conf, 111);
    if ($Conf->settings["allowPaperOption"] == 111) {
        update_schema_review_word_counts($Conf);
        update_schema_version($Conf, 112);
    }
    if ($Conf->settings["allowPaperOption"] == 112
        && Dbl::ql("alter table ContactInfo add `passwordUseTime` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table ContactInfo add `updateTime` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("update ContactInfo set passwordUseTime=lastLogin where passwordUseTime=0"))
        update_schema_version($Conf, 113);
}

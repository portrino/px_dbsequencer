#
# Table structure for table 'tx_pxdbsequencer_sequence'
#
CREATE TABLE tx_pxdbsequencer_sequence (
	table_name varchar(190) DEFAULT '' NOT NULL,
	current int(30) DEFAULT '0' NOT NULL,
	offset int(30) DEFAULT '1' NOT NULL,
	timestamp int(30) DEFAULT '0' NOT NULL,

	UNIQUE KEY table_name (table_name)
);

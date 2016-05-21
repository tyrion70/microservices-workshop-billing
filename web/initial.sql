CREATE TABLE  `microservicesbilling`.`orders` (
`ordernumber` INT NOT NULL ,
`state` VARCHAR( 100 ) NOT NULL ,
`user` VARCHAR( 100 ) NOT NULL ,
`name` VARCHAR( 100 ) NOT NULL ,
`phone` VARCHAR( 100 ) NOT NULL ,
`email` VARCHAR( 100 ) NOT NULL ,
`street` VARCHAR( 100 ) NOT NULL ,
`city` VARCHAR( 100 ) NOT NULL ,
`postcode` VARCHAR( 100 ) NOT NULL
) ENGINE = MYISAM ;

INSERT INTO  `microservicesbilling`.`orders` (
`ordernumber` ,
`state` ,
`user` ,
`name` ,
`phone` ,
`email` ,
`street` ,
`city` ,
`postcode`
)
VALUES (
'32',  'processing',  'Willem Dekker',  'Willem Dekker',  '0612345678',  'ww@ww.com',  'straat 65',  'stadstad',  '5444  cd'
);

[
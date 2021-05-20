CREATE TABLE `filteraddressbook_contact_map`(
`id` int(11) NOT NULL,
`addressBookId` INT(11) DEFAULT NULL,
`contactId` INT(11) DEFAULT NULL
)ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = COMPACT;

ALTER TABLE `filteraddressbook_contact_map`
  ADD PRIMARY KEY (`id`),
  ADD KEY `addressBookId` (`addressBookId`),
  ADD KEY `contactId` (`contactId`);

ALTER TABLE `filteraddressbook_contact_map`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `filteraddressbook_contact_map`
  ADD CONSTRAINT `addressBookId_fk1` FOREIGN KEY (`addressBookId`) REFERENCES `addressbook_addressbook` (`id`);

ALTER TABLE `filteraddressbook_contact_map`
  ADD CONSTRAINT `contactId_fk1` FOREIGN KEY (`contactId`) REFERENCES `addressbook_contact` (`id`);

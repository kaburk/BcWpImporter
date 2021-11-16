<?php
class BcWpImportersController extends AppController {

    public $name = 'BcWpImporters';

    public $uses = [
        'Blog.BlogContent',
        'Blog.BlogPost',
        'Blog.BlogCategory',
        'Blog.BlogTag',
        'Blog.BlogComment',
        'Content',
        'SiteConfig',
    ];

    public $components = [
        'BcAuth',
        'Cookie',
        'BcAuthConfigure'
    ];

    public $subMenuElements = [
        'bc_importer',
    ];

    public $crumbs = [
        [
            'name' => 'WordPressデータインポート',
            'url' => [
                'plugin' => 'bc_importer',
                'controller' => 'bc_importers',
                'action' => 'index'
            ],
        ],
    ];

    public $pageTitle = 'WordPressデータインポート';

    public $blogContent;
    public $userList;

    /**
     * admin_index
     */
    public function admin_index() {

        if ($this->request->data) {
            if (empty($this->request->data['BcWpImporter']['file']['tmp_name'])) {
                $this->BcMessage->setError('[BcWpImporter] ファイルのアップロードに失敗しました。');
            } else {
                $blogContentId = $this->request->data['BcWpImporter']['blog_content_id'];
                $clearData = $this->request->data['BcWpImporter']['clear_data'];

                $name = $this->request->data['BcWpImporter']['file']['name'];
                move_uploaded_file($this->request->data['BcWpImporter']['file']['tmp_name'], TMP . $name);

                if ($this->blog_import(TMP . $name, $blogContentId, $clearData)) {
                    $this->BcMessage->setSuccess('[BcWpImporter] ファイルの読み込みに成功しました。');
                } else {
                    $this->BcMessage->setError('[BcWpImporter] ファイルの読み込みに失敗しました。');
                }
            }
        }

        $contents = $this->Content->find('all', [
            'conditions' => [
                'Content.plugin' => 'Blog',
                'Content.type' => 'BlogContent',
                'OR' => [
                    ['Content.alias_id' => ''],
                    ['Content.alias_id' => NULL],
                ],
            ],
            'order' => [
                'Content.entity_id'
            ],
        ]);
        // 名サイトとサブサイトで同じ名称のブログが有る可能性があるので、
        // プルダウンにサイト名を付加
        foreach ($contents as $content) {
            if ($content['Site']['id']) {
                $siteName = $content['Site']['name'];
            } else {
                $bcSite = Configure::read('BcSite');
                $siteName = $bcSite['main_site_display_name'];
            }
            $blogContents[$content['Content']['entity_id']] = sprintf(
                '%s : %s',
                $siteName,
                $content['Content']['title']
            );
        }

        $this->set('blogContents', $blogContents);
    }

    /**
     * blog_import
     *
     * param  string $fileName
     * param integer $blogContentId
     * param integer $clearData
     */
    private function blog_import($fileName, $blogContentId, $clearData = 1) {

        set_time_limit(0);
        ini_set('memory_limit', -1);
        ini_set("max_execution_time", 0);
        ini_set("max_input_time", 0);
        clearAllCache();

        if ($clearData) {
            $db = ConnectionManager::getDataSource($this->BlogPost->useDbConfig);
            $dbPrefix = $db->config['prefix'];
            $this->BlogPost->deleteAll(['BlogPost.blog_content_id' => $blogContentId], false);
            $this->BlogComment->deleteAll(['BlogComment.blog_content_id' => $blogContentId], false);
            $this->BlogPost->query('ALTER TABLE `' . $dbPrefix . 'blog_posts` AUTO_INCREMENT=1;');
            $this->BlogCategory->query('ALTER TABLE `' . $dbPrefix . 'blog_categories` AUTO_INCREMENT=1;');
            $this->BlogComment->query('ALTER TABLE `' . $dbPrefix . 'blog_comments` AUTO_INCREMENT=1;');
        }

        $ret = false;
        if (empty($blogContentId)) {
            return $ret;
        }

        $this->blogContent = $this->BlogContent->find('first', [
            'conditions' => [
                'BlogContent.id' => $blogContentId,
            ],
            'recursive' => -1,
        ]);
        $this->userList = $this->User->find('list', [
            'fields' => ['nickname', 'id'],
            'recursive' => -1,
        ]);

        try {
            // 通常のXMLの場合はこちらでOK
            App::uses('Xml', 'Utility');
            $xmlArray = Xml::toArray(Xml::build($fileName));
            $xmlItems = $xmlArray['rss']['channel']['item'];
        } catch (Exception $e) {
            // 古いWordPressで記事内の顔文字などで失敗する例があったので別途処理も実装ている
            // その場合、invalidなXMLを読み込む為の処理に無理やりあわせる
            $xmlItems = $this->readWpXml($fileName);
        }

        if (isset($xmlItems)) {
            if (!isset($xmlItems[0])) {
                $workArray = $xmlItems;
                $xmlItems = [];
                $xmlItems[0] = $workArray;
            }
            foreach ($xmlItems as $xml) {
                $this->log($xml, LOG_BCWPIMPORTER);
                clearAllCache();
                switch ($xml['wp:post_type']) {
                    case 'post':
                        $this->makePostData($xml);
                        break;
                    case 'page':
                        // TODO
                        // $this->makePageData($xml);
                        break;
                    default:
                        break;
                }
            }
            $ret = true;
            clearAllCache();
        }

        return $ret;
    }

    /**
     * makePostData
     *
     * param array $xml
     */
    private function makePostData($xml) {

        $blogContentId = $this->blogContent['BlogContent']['id'];

        // 記事の作成
        $data = [];

        // 属するブログを設定
        $data['BlogPost']['blog_content_id'] = $blogContentId;

        // 記事のNOを設定
        $data['BlogPost']['no'] = $this->BlogPost->getMax(
            'no',
            [
                'BlogPost.blog_content_id' => $blogContentId
            ]
        ) + 1;
        // $data['BlogPost']['no'] = $xml['wp:post_id'];

        // 記事タイトルを設定
        $data['BlogPost']['name'] = mb_substr($xml['title'], 0, 50);

        // 記事本文を設定
        if (isset($xml['content:encoded'])) {

            // 画像URLを変換
            $xml['content:encoded'] = preg_replace(
                '/http:\/\/(.*?)\/wp-content\/uploads\//i',
                baseUrl() . 'files/wp-content/uploads/',
                $xml['content:encoded']
            );
            $data['BlogPost']['detail'] = nl2br($xml['content:encoded']);
        } else {
            $data['BlogPost']['detail'] = '';
        }
        // 記事概要を設定
        if (isset($xml['excerpt:encoded'])) {
            $data['BlogPost']['content'] = $xml['excerpt:encoded'];
        } else {
            $data['BlogPost']['content'] = '';
        }

        // 記事カテゴリ・タグを設定
        if (isset($xml['category'])) {
            //$this->log($xml['category'], LOG_DEBUG);

            // ブログカテゴリの場合
            if (!isset($xml['category'][0])) {
                $workArray = $xml['category'];
                $xml['category'] = [];
                $xml['category'][0] = $workArray;
            }
            // カテゴリが複数の場合は最初のみを設定する
            $category = [];
            foreach ($xml['category'] as $key => $item) {
                if ($item['@domain'] === 'category') {
                    $category = $item;
                    break;
                }
            }
            if ($category) {
                $no = $this->BlogCategory->getMax(
                    'no',
                    [
                        'BlogCategory.blog_content_id' => $blogContentId
                    ]
                ) + 1;
                if (isset($category['@'])) {
                    $catecoryTitle = mb_substr($category['@'], 0, 50);
                } else {
                    $catecoryTitle = 'category-title-' . $no;
                }
                if (isset($category['@nicename'])) {
                    $catecoryName  = mb_substr($category['@nicename'], 0, 50);
                } else {
                    $catecoryName = 'category-name-' . $no;
                }

                // カテゴリのチェック
                $this->BlogCategory->cacheQueries = false;
                $categoryData = $this->BlogCategory->find('first', [
                    'conditions' => [
                        'BlogCategory.blog_content_id' => $blogContentId,
                        'BlogCategory.title' => $catecoryTitle,
                    ],
                    'recursive' => -1,
                    'cache' => false,
                ]);

                if (isset($categoryData['BlogCategory']['id'])) {
                    $data['BlogPost']['blog_category_id'] = $categoryData['BlogCategory']['id'];
                } else {
                    // なければカテゴリを新規追加
                    $data['BlogCategory'] = [];
                    $data['BlogCategory']['blog_content_id'] = $blogContentId;
                    $data['BlogCategory']['no'] = $no;
                    $data['BlogCategory']['name'] = $catecoryName;
                    $data['BlogCategory']['title'] = $catecoryTitle;
                    $data['BlogCategory']['lft'] = $this->BlogCategory->getMax(
                        'rght',
                        [
                            'BlogCategory.blog_content_id' => $blogContentId
                        ]
                    ) + 1;
                    $data['BlogCategory']['rght'] = $data['BlogCategory']['lft'] + 1;
                    $data['BlogCategory']['owner_id'] = null;
                    $data['BlogCategory']['parent_id'] = null;
                    $data['BlogCategory']['status'] = null;
                    $this->BlogCategory->validationParams['blogContentId'] = $blogContentId;
                    $this->BlogCategory->create($data);
                    $ret = $this->BlogCategory->save($data, false);

                    $categoryId = $this->BlogCategory->getLastInsertID();
                    $data['BlogPost']['blog_category_id'] = $categoryId;

                    $this->BlogCategory->updateAll(
                        [
                            'lft'  => "'" . $data['BlogCategory']['lft'] . "'",
                            'rght' => "'" . $data['BlogCategory']['rght'] . "'",
                        ],
                        [
                            'BlogCategory.id' => $categoryId
                        ]
                    );

                    unset($data['BlogCategory']);
                }
            }

            // ブログタグ
            foreach ($xml['category'] as $key => $item) {
                if ($item['@domain'] === 'post_tag') {
                    if (isset($category['@'])) {
                        $tagName = mb_substr($category['@'], 0, 100);
                        // タグのチェック
                        $this->BlogTag->cacheQueries = false;
                        $tagData = $this->BlogTag->find('first', [
                            'conditions' => [
                                'BlogTag.name' => $tagName,
                            ],
                            'recursive' => -1,
                            'cache' => false,
                        ]);
                        if (isset($tagData['BlogTag']['id'])) {
                            $data['BlogTag']['BlogTag'][] = $tagData['BlogTag']['id'];
                        } else {
                            // なければタグを新規追加
                            $tagData['BlogTag'] = [];
                            $tagData['BlogTag']['name'] = $tagName;
                            $this->BlogTag->create($tagData);
                            $ret = $this->BlogTag->save($tagData, false);

                            $tagId = $this->BlogTag->getLastInsertID();
                            $data['BlogTag']['BlogTag'][] = $tagId;
                        }
                    }
                }
            }
        }

        // 投稿ユーザを設定
        $authorId = '';
        if (isset($xml['dc:creator']) && isset($this->userList[$xml['dc:creator']])) {
            $authorId = $this->userList[$xml['dc:creator']];
        } else {
            $authorId = '1'; // システム管理者
        }
        $data['BlogPost']['user_id'] = $authorId;

        // 投稿日を設定
        if ($xml['wp:post_date']) {
            $postDate = date('Y-m-d H:i:s', strtotime($xml['wp:post_date']));
        } else {
            $postDate = date('Y-m-d H:i:s');
        }
        $data['BlogPost']['posts_date'] = $postDate;

        // 表示開始日を設定
        $data['BlogPost']['publish_begin'] = null;

        // 表示終了日を設定
        $data['BlogPost']['publish_end'] = null;

        // 公開状態を設定：非公開は0、公開は1
        if ($xml['wp:status'] === 'publish') {
            $data['BlogPost']['status'] = true;
        } elseif ($xml['wp:status'] === 'future') {
            $data['BlogPost']['status'] = true;
            $data['BlogPost']['publish_begin'] = $postDate;
            $data['BlogPost']['publish_end'] = null;
        } else {
            $data['BlogPost']['status'] = false;
        }

        // 本文下書きを設定
        $data['BlogPost']['content_draft'] = null;

        // 詳細下書きを設定
        $data['BlogPost']['detail_draft'] = null;

        // 検索除外を設定
        $data['BlogPost']['exclude_search'] = 0;

        // アイキャッチ
        $data['BlogPost']['eye_catch'] = null;

        // 登録日を設定
        $data['BlogPost']['created'] = $postDate;

        // 更新日を設定
        $data['BlogPost']['modified'] = $postDate;

        // コメント
        if (isset($xml['wp:comment'])) {
            if (!isset($xml['wp:comment'][0])) {
                $workArray = $xml['wp:comment'];
                $xml['wp:comment'] = [];
                $xml['wp:comment'][0] = $workArray;
            }
            foreach ($xml['wp:comment'] as $key => $comment) {
                if ($comment['wp:comment_approved'] != 'spam') {
                    $data['BlogComment'][$key]['id'] = $comment['wp:comment_id'];
                    $data['BlogComment'][$key]['blog_content_id'] = $blogContentId;
                    $data['BlogComment'][$key]['no'] = $comment['wp:comment_id'];
                    $data['BlogComment'][$key]['status'] = $comment['wp:comment_approved'];
                    $data['BlogComment'][$key]['name'] = mb_substr($comment['wp:comment_author'], 0, 50);
                    $data['BlogComment'][$key]['email'] = mb_substr($comment['wp:comment_author_email'], 0, 255);
                    if ($comment['wp:comment_author_url'] === 'http://') {
                        $comment['wp:comment_author_url'] = '';
                    }
                    $data['BlogComment'][$key]['url'] = mb_substr($comment['wp:comment_author_url'], 0, 255);
                    $data['BlogComment'][$key]['message'] = $comment['wp:comment_content'];
                    $data['BlogComment'][$key]['created'] = $comment['wp:comment_date'];
                    $data['BlogComment'][$key]['modified'] = $comment['wp:comment_date'];
                }
            }
        }

        $ret = false;

        // 保存
        if (isset($data)) {

            unset($this->BlogPost->BlogComment->validate['url']);
            unset($this->BlogPost->BlogComment->validate['email']);
            $this->BlogPost->create();
            $ret = $this->BlogPost->saveAll($data, ['validate' => false, 'callbacks' => false]);
            if ($ret) {
                $blogPostId = $this->BlogPost->getLastInsertID();
                $this->BlogPost->updateAll(
                    [
                        'created'  => "'" . $data['BlogPost']['created'] . "'",
                        'modified' => "'" . $data['BlogPost']['modified'] . "'",
                    ],
                    [
                        'BlogPost.id' => $blogPostId
                    ]
                );
                if (isset($data['BlogComment'])) {
                    foreach ($data['BlogComment'] as $comment) {
                        $this->BlogPost->BlogComment->updateAll(
                            [
                                'created'  => "'" . $comment['created'] . "'",
                                'modified' => "'" . $comment['modified'] . "'",
                            ],
                            [
                                'BlogComment.id' => $comment['id']
                            ]
                        );
                    }
                }
            } else {
                $msg = '[BcWpImporter] 移行データ No.' . $data['BlogPost']['no'] . ' の保存に失敗しました。' .
                    '（詳しくはエラーログを確認してください。）';
                $this->BcMessage->setError($msg, true);
                $this->log($msg, LOG_ERR);
                $this->log($ret, LOG_ERR);
                $this->log($this->BlogPost->validationErrors, LOG_ERR);
                $this->log('---------------------------------------------------');
                $this->log($xml, LOG_ERR);
                $this->log('---------------------------------------------------');
                $this->log($data, LOG_ERR);
                $this->log('---------------------------------------------------');
                return false;
            }

            $this->log($data, LOG_BCWPIMPORTER);
        }
        return $ret;
    }

    /**
     * makePageData
     *
     * param array $xml
     */
    private function makePageData($xml) {
        // TODO 固定ページ
        return true;
    }

    /**
     * readWpXml
     */
    private function readWpXml($fileName) {

        set_magic_quotes_runtime(0);
        $importdata = array_map('rtrim', file($fileName));
        $posts = [];
        // $categories = [];
        $num = 0;
        $doing_entry = false;
        foreach ($importdata as $importline) {
            if (false !== strpos($importline, '<item>')) {
                $posts[$num] = '';
                $doing_entry = true;
                continue;
            }
            if (false !== strpos($importline, '</item>')) {
                $num++;
                $doing_entry = false;
                continue;
            }
            if ($doing_entry) {
                $posts[$num] .= $importline . "\n";
            }
        }

        $data = [];
        foreach ($posts as $post) {
            $post_ID = (int) $this->getTag($post, 'wp:post_id');
            $item = $this->processPost($post);
            if ($item) {
                $data[$post_ID] = $item;
            }
        }
        return $data;
    }

    /**
     * getTag
     *
     * param string $string
     * param string $tag
     * return $array || $string
     */
    private function getTag($string, $tag) {
        preg_match("|<$tag.*?>(.*?)</$tag>|is", $string, $return);
        $return = preg_replace('|^<!\[CDATA\[(.*)\]\]>$|s', '$1', $return[1]);
        $return = trim($return);
        return $return;
    }

    /**
     * processPost
     *
     * param array $post
     */
    private function processPost($post) {

        $tags = [
            'title',
            'wp:post_id',
            'wp:post_date',
            'wp:post_date_gmt',
            'wp:comment_status',
            'wp:ping_status',
            'wp:status',
            'wp:post_name',
            'wp:post_parent',
            'wp:menu_order',
            'wp:post_type',
            'guid',
            'dc:creator',
            'content:encoded',
        ];
        $item = [];
        foreach ($tags as $tag) {
            $item[$tag] = $this->getTag($post, $tag);
        }

        $post_content = $item['content:encoded'];
        $post_content = preg_replace('|<(/?[A-Z]+)|e', "'<' . strtolower('$1')", $post_content);
        $post_content = str_replace('<br>', '<br />', $post_content);
        $post_content = str_replace('<hr>', '<hr />', $post_content);
        $item['content:encoded'] = $post_content;

        if (preg_match_all('|<category domain="(.*?)" nicename="(.*?)">(.*?)</category>|is', $post, $categoryMatches) !== false) {
            $categories = [];
            foreach ($categoryMatches[0] as $i => $category) {
                $categories[] = [
                    '@' => h(html_entity_decode(str_replace(['<![CDATA[', ']]>'], '', $categoryMatches[3][$i]))),
                    '@nicename' => $categoryMatches[2][$i],
                    '@domain' => $categoryMatches[1][$i],
                ];
            }
            $item['category'] = $categories;
        } elseif (preg_match_all('|<category>(.*?)</category>|is', $post, $categories) !== false) {
            $categories = $categories[1];
            foreach ($categories as $i => $category) {
                $categories[$i] = h(html_entity_decode(str_replace(['<![CDATA[', ']]>'], '', $category)));
            }
            $item['category'] = $categories;
        }

        // Now for comments
        preg_match_all('|<wp:comment>(.*?)</wp:comment>|is', $post, $comments);
        $comments = $comments[1];
        if ($comments) {
            $commentTags = [
                'wp:comment_id',
                'wp:comment_author',
                'wp:comment_author_email',
                'wp:comment_author_IP',
                'wp:comment_author_url',
                'wp:comment_date',
                'wp:comment_date_gmt',
                'wp:comment_content',
                'wp:comment_approved',
                'wp:comment_type',
                'wp:comment_parent',
            ];
            $num_comments = 0;
            foreach ($comments as $comment) {
                foreach ($commentTags as $commentTag) {
                    $item['wp:comment'][$num_comments][$commentTag] = $this->getTag($comment, $commentTag);
                }
                $num_comments++;
            }
        }

        // Now for post meta
        preg_match_all('|<wp:postmeta>(.*?)</wp:postmeta>|is', $post, $postmeta);
        $postmeta = $postmeta[1];
        if ($postmeta) {
            $postmetaTags = [
                'wp:meta_key',
                'wp:meta_value',
            ];
            $num_postmeta = 0;
            foreach ($postmeta as $p) {
                foreach ($postmetaTags as $postmetaTag) {
                    $item['wp:postmeta'][$num_postmeta][$postmetaTag] = $this->getTag($p, $postmetaTag);
                }
                $num_postmeta++;
            }
        }
        return $item;
    }
}

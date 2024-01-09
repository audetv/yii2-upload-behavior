<?php
/**
 * @author Valentin Konusov <rlng-krsk@yandex.ru>
 * @author Alexey Samoylov <alexey.samoylov@gmail.com>
 * @link http://yiidreamteam.com/yii2/upload-behavior
 */
namespace yiidreamteam\upload;

use Yii;
use yii\base\InvalidCallException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\db\BaseActiveRecord;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\helpers\VarDumper;
use yii\web\UploadedFile;
use yiidreamteam\upload\exceptions\FileUploadException;

/**
 * Class FileUploadBehavior
 *
 * @property ActiveRecord $owner
 */
class FileUploadBehavior extends \yii\base\Behavior
{
    const EVENT_AFTER_FILE_SAVE = 'afterFileSave';

    /** @var string Name of attribute which holds the attachment. */
    public $attribute = 'upload';

    /** @var string Path template to use in storing files.5 */
    public $filePath = '@webroot/uploads/[[pk]].[[extension]]';

    /** @var string Where to store images. */
    public $fileUrl = '/uploads/[[pk]].[[extension]]';

    /**
     * @var string Attribute used to link owner model with it's parent
     * @deprecated Use attribute_xxx placeholder instead
     */
    public $parentRelationAttribute;

    /** @var \yii\web\UploadedFile */
    protected $file;

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
        ];
    }

    /**
     * Before validate event.
     */
    public function beforeValidate()
    {
        if ($this->owner->{$this->attribute} instanceof UploadedFile) {
            $this->file = $this->owner->{$this->attribute};
            return;
        }

        $this->file = UploadedFile::getInstance($this->owner, $this->attribute);

        if (empty($this->file)) {
            $this->file = UploadedFile::getInstanceByName($this->attribute);
        }

        if ($this->file instanceof UploadedFile) {
            $this->owner->{$this->attribute} = $this->file;
        }
    }

    /**
     * Before save event.
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function beforeSave()
    {
        if ($this->file instanceof UploadedFile) {

            if (true !== $this->owner->isNewRecord) {
                /** @var ActiveRecord $oldModel */
                $oldModel = $this->owner->findOne($this->owner->primaryKey);
                $behavior = static::getInstance($oldModel, $this->attribute);
                $behavior->cleanFiles();
            }

            $this->owner->{$this->attribute} = implode('.',
                array_filter([$this->file->baseName, $this->file->extension])
            );
        } else {
            if (true !== $this->owner->isNewRecord && empty($this->owner->{$this->attribute})) {
                $this->owner->{$this->attribute} = ArrayHelper::getValue($this->owner->oldAttributes, $this->attribute,
                    null);
            }
        }
    }

    /**
     * Returns behavior instance for specified object and attribute
     *
     * @param Model $model
     * @param string $attribute
     * @return static
     */
    public static function getInstance(Model $model, $attribute)
    {
        foreach ($model->behaviors as $behavior) {
            if ($behavior instanceof self && $behavior->attribute == $attribute) {
                return $behavior;
            }
        }

        throw new InvalidCallException('Missing behavior for attribute ' . VarDumper::dumpAsString($attribute));
    }

    /**
     * Removes files associated with attribute
     */
    public function cleanFiles()
    {
        $path = $this->resolvePath($this->filePath);
        @unlink($path);
    }

    /**
     * Replaces all placeholders in path variable with corresponding values
     *
     * @param string $path
     * @return string
     */
    public function resolvePath($path)
    {
        $path = Yii::getAlias($path);

        $pi = pathinfo($this->owner->{$this->attribute}) ?: "";
        $fileName = ArrayHelper::getValue($pi, 'filename');
        $extension = isset($pi['extension']) ? strtolower($pi['extension']) : "";

        $replacements = array(
            'extension' => $extension,
            'filename' => $fileName,
            'basename' => implode('.', array_filter([$fileName, $extension])),
            'app_root' => Yii::getAlias('@app'),
            'web_root' => Yii::getAlias('@webroot'),
            'base_url' => Yii::getAlias('@web'),
            'model' => lcfirst((new \ReflectionClass($this->owner->className()))->getShortName()),
            'attribute' => lcfirst($this->attribute),
            'id' => lcfirst(implode('_', $this->owner->getPrimaryKey(true))),
            'pk' => lcfirst(implode('_', $this->owner->getPrimaryKey(true))),
            'id_path' => static::makeIdPath($this->owner->getPrimaryKey()),
            'parent_id' => $this->owner->{$this->parentRelationAttribute},
        );

        $replaceAttribute = function ($name) use ($replacements) {
            if (preg_match('/^attribute_(\w+)$/', $name, $matches)) {
                $attribute = $matches[1];
                return $this->owner->{$attribute};
            }
            return '[[' . $name . ']]';
        };

        $replaceMd5Attribute = function ($name) use ($replacements) {
            if (preg_match('/^md5_attribute_(\w+)$/', $name, $matches)) {
                $attribute = $matches[1];
                return md5($this->owner->{$attribute});
            }
            return '[[' . $name . ']]';
        };

        return preg_replace_callback('|\[\[([\w\_/]+)\]\]|', function ($matches) use ($replacements, $replaceAttribute, $replaceMd5Attribute) {
            $name = $matches[1];
            return isset($replacements[$name]) ? $replacements[$name] : ($replaceAttribute($name) ?: $replaceMd5Attribute($name));
        }, $path);
    }

    /**
     * @param integer $id
     * @return string
     */
    protected static function makeIdPath($id)
    {
        $id = is_array($id) ? implode('', $id) : $id;
        $length = 10;
        $id = str_pad($id, $length, '0', STR_PAD_RIGHT);

        $result = [];
        for ($i = 0; $i < $length; $i++) {
            $result[] = substr($id, $i, 1);
        }

        return implode('/', $result);
    }

    /**
     * After save event.
     */
    public function afterSave()
    {
        if ($this->file instanceof UploadedFile !== true) {
            return;
        }

        $path = $this->getUploadedFilePath($this->attribute);

        FileHelper::createDirectory(pathinfo($path, PATHINFO_DIRNAME), 0775, true);

        if (!$this->file->saveAs($path)) {
            throw new FileUploadException($this->file->error, 'File saving error.');
        }

        $this->owner->trigger(static::EVENT_AFTER_FILE_SAVE);
    }

    /**
     * Returns file path for attribute.
     *
     * @param string $attribute
     * @return string
     */
    public function getUploadedFilePath($attribute)
    {
        $behavior = static::getInstance($this->owner, $attribute);

        if (!$this->owner->{$attribute}) {
            return '';
        }

        return $behavior->resolvePath($behavior->filePath);
    }

    /**
     * Before delete event.
     */
    public function beforeDelete()
    {
        $this->cleanFiles();
    }

    /**
     * Returns file url for the attribute.
     *
     * @param string $attribute
     * @return string|null
     */
    public function getUploadedFileUrl($attribute)
    {
        if (!$this->owner->{$attribute}) {
            return null;
        }

        $behavior = static::getInstance($this->owner, $attribute);

        return $behavior->resolvePath($behavior->fileUrl);
    }
}
